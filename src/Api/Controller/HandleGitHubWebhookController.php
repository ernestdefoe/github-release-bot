<?php

namespace ErnestDefoe\GitHubReleaseBot\Api\Controller;

use ErnestDefoe\GitHubReleaseBot\ReleasePoster;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class HandleGitHubWebhookController implements RequestHandlerInterface
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected ReleasePoster $poster,
        protected LoggerInterface $log,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $secret = (string) $this->settings->get('ernestdefoe-github-release-bot.webhook_secret');
        $botUserId = (int) $this->settings->get('ernestdefoe-github-release-bot.bot_user_id');
        $map = json_decode((string) $this->settings->get('ernestdefoe-github-release-bot.repo_map'), true);

        if ($secret === '' || $botUserId <= 0 || ! is_array($map)) {
            return new JsonResponse(['error' => 'not_configured'], 503);
        }

        // Read raw body for signature verification (must be byte-for-byte
        // identical to what GitHub signed, so do this before any parsing).
        $body = (string) $request->getBody();
        $expected = 'sha256='.hash_hmac('sha256', $body, $secret);

        if (! hash_equals($expected, $request->getHeaderLine('X-Hub-Signature-256'))) {
            return new JsonResponse(['error' => 'invalid_signature'], 401);
        }

        $event = $request->getHeaderLine('X-GitHub-Event');
        if ($event === 'ping') {
            return new JsonResponse(['pong' => true], 200);
        }
        if ($event !== 'release') {
            return new JsonResponse(['ignored' => 'not_release_event'], 200);
        }

        $payload = json_decode($body, true);
        if (! is_array($payload) || ($payload['action'] ?? null) !== 'published') {
            return new JsonResponse(['ignored' => 'not_published_action'], 200);
        }

        $repoName = $payload['repository']['name'] ?? null;
        if (! $repoName || ! isset($map[$repoName])) {
            return new JsonResponse(['ignored' => 'repo_not_mapped', 'repo' => $repoName], 200);
        }

        return $this->postRelease($request, $botUserId, (string) $repoName, (int) $map[$repoName], $payload['release'] ?? []);
    }

    private function postRelease(ServerRequestInterface $request, int $botUserId, string $repoName, int $discussionId, array $release): ResponseInterface
    {
        try {
            $this->poster->post(
                $botUserId,
                $discussionId,
                trim($repoName.' '.($release['tag_name'] ?? '')),
                (string) ($release['body'] ?? ''),
                (string) ($release['html_url'] ?? ''),
                $request->getServerParams()['REMOTE_ADDR'] ?? null,
            );
        } catch (ModelNotFoundException $e) {
            return $e->getModel() === User::class
                ? new JsonResponse(['error' => 'bot_user_not_found', 'user_id' => $botUserId], 503)
                : new JsonResponse(['error' => 'discussion_not_found', 'discussion_id' => $discussionId], 500);
        } catch (Throwable $e) {
            // The detail goes to the log only; the response is read by GitHub
            // (and anyone who can see the webhook's delivery log).
            $this->log->warning('[github-release-bot] post failed', [
                'repo' => $repoName,
                'discussion_id' => $discussionId,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse(['error' => 'post_failed'], 500);
        }

        return new JsonResponse(['ok' => true, 'repo' => $repoName, 'discussion' => $discussionId], 200);
    }
}
