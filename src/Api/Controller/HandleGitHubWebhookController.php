<?php

namespace ErnestDefoe\GitHubReleaseBot\Api\Controller;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\UserRepository;
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
        protected UserRepository $users,
        protected LoggerInterface $log,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $secret    = (string) $this->settings->get('ernestdefoe-github-release-bot.webhook_secret');
        $botUserId = (int)    $this->settings->get('ernestdefoe-github-release-bot.bot_user_id');
        $mapJson   = (string) $this->settings->get('ernestdefoe-github-release-bot.repo_map');

        if ($secret === '' || $botUserId <= 0 || $mapJson === '') {
            return new JsonResponse(['error' => 'not_configured'], 503);
        }

        // Read raw body for signature verification (must be byte-for-byte
        // identical to what GitHub signed, so do this before any parsing).
        $body = (string) $request->getBody();
        $sig  = $request->getHeaderLine('X-Hub-Signature-256');

        $expected = 'sha256='.hash_hmac('sha256', $body, $secret);
        if (! hash_equals($expected, $sig)) {
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
        $release  = $payload['release'] ?? [];

        $map = json_decode($mapJson, true);
        if (! is_array($map) || ! $repoName || ! isset($map[$repoName])) {
            return new JsonResponse(['ignored' => 'repo_not_mapped', 'repo' => $repoName], 200);
        }

        $discussionId = (int) $map[$repoName];

        try {
            $bot = $this->users->findOrFail($botUserId);
        } catch (ModelNotFoundException $e) {
            return new JsonResponse(['error' => 'bot_user_not_found', 'user_id' => $botUserId], 503);
        }

        $tag   = (string) ($release['tag_name'] ?? '');
        $notes = trim((string) ($release['body'] ?? ''));
        $url   = (string) ($release['html_url'] ?? '');

        if ($notes === '') {
            $notes = '_No release notes provided._';
        }

        $heading = trim("{$repoName} {$tag}");
        $content = "## {$heading}\n\n{$notes}";
        if ($url !== '') {
            $content .= "\n\n[View release on GitHub]({$url})";
        }

        try {
            /** @var Discussion $discussion */
            $discussion = Discussion::query()->findOrFail($discussionId);

            // type + number are auto-assigned by Post::boot(); last_post / comment
            // counts are bumped by DiscussionMetadataUpdater on the Posted event.
            $post = new CommentPost();
            $post->setContentAttribute($content, $bot);
            $post->created_at    = Carbon::now();
            $post->user_id       = $bot->id;
            $post->discussion_id = $discussion->id;
            $post->ip_address    = $request->getServerParams()['REMOTE_ADDR'] ?? null;
            $post->save();

            // Posted-event listeners that normally refresh discussion stats don't
            // always fire when posts are created outside the JSON:API endpoint,
            // so update them here to keep the thread display in sync.
            $discussion->refreshLastPost();
            $discussion->refreshCommentCount();
            $discussion->refreshParticipantCount();
            $discussion->save();
        } catch (ModelNotFoundException $e) {
            return new JsonResponse(['error' => 'discussion_not_found', 'discussion_id' => $discussionId], 500);
        } catch (Throwable $e) {
            $this->log->warning('[github-release-bot] post failed', [
                'repo'          => $repoName,
                'discussion_id' => $discussionId,
                'error'         => $e->getMessage(),
            ]);

            return new JsonResponse(['error' => 'post_failed', 'message' => $e->getMessage()], 500);
        }

        return new JsonResponse(['ok' => true, 'repo' => $repoName, 'discussion' => $discussionId], 200);
    }
}
