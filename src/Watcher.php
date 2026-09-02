<?php

namespace ErnestDefoe\GitHubReleaseBot;

use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Follows releases in repositories we do not own.
 *
 * 🚨 Why this exists at all: the webhook path can only ever cover repositories
 * you administer, because adding a webhook requires admin on the repo. Someone
 * running a forum ABOUT other people's extensions has no such access, so the
 * only way to hear about their releases is to go and look.
 *
 * Two requests, deliberately split:
 *
 *  - Detection reads `https://github.com/{owner}/{repo}/releases.atom`. That
 *    feed is public, needs no token, and does not touch the REST rate limit —
 *    so polling fifty repositories every half hour costs nothing and cannot
 *    lock anyone out of the API.
 *
 *  - The notes are fetched from the REST API only when the feed shows something
 *    new, because the feed carries rendered HTML while the API carries the
 *    original markdown — the same field the webhook delivers. That keeps a
 *    watched release and an owned release identical in the thread, and it means
 *    the rate limit is spent per RELEASE, not per poll.
 */
class Watcher
{
    private const FEED = 'https://github.com/%s/releases.atom';
    private const API  = 'https://api.github.com/repos/%s/releases/tags/%s';

    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings,
        protected ReleasePoster $poster,
        protected LoggerInterface $log,
    ) {
    }

    /** @return array{checked:int, posted:int, adopted:int, failed:int, details:list<string>} */
    public function run(bool $dryRun = false): array
    {
        $map = json_decode((string) $this->settings->get('ernestdefoe-github-release-bot.watch_map'), true);
        $botUserId = (int) $this->settings->get('ernestdefoe-github-release-bot.bot_user_id');

        $result = ['checked' => 0, 'posted' => 0, 'adopted' => 0, 'failed' => 0, 'details' => []];

        if (! is_array($map) || $map === [] || $botUserId <= 0) {
            return $result;
        }

        $client = new Client([
            'timeout'         => 15,
            'connect_timeout' => 8,
            'headers'         => [
                // GitHub asks for an identifying agent and rejects requests without one.
                'User-Agent' => 'flarum-github-release-bot',
            ],
        ]);

        foreach ($map as $repo => $discussionId) {
            $repo = trim((string) $repo);
            $discussionId = (int) $discussionId;

            if ($repo === '' || $discussionId <= 0 || ! str_contains($repo, '/')) {
                continue;
            }

            $result['checked']++;

            try {
                $this->checkOne($client, $repo, $discussionId, $botUserId, $dryRun, $result);
            } catch (Throwable $e) {
                $result['failed']++;
                $result['details'][] = "{$repo}: {$e->getMessage()}";
                $this->recordFailure($repo, $e->getMessage());
                $this->log->warning('[github-release-bot] watch failed', [
                    'repo' => $repo, 'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    private function checkOne(
        Client $client,
        string $repo,
        int $discussionId,
        int $botUserId,
        bool $dryRun,
        array &$result
    ): void {
        $xml = (string) $client->get(sprintf(self::FEED, $repo))->getBody();

        $latest = $this->newestEntry($xml);

        if (! $latest) {
            // A repository with no releases at all is not an error; it may get
            // one later. Record the check so the row exists and stays fresh.
            $this->touch($repo, null, null);
            return;
        }

        $seen = $this->db->table('github_release_bot_seen')->where('repo', $repo)->first();

        /*
         * 🚨 First sight adopts, it does not announce.
         *
         * Without this, adding a repository to the watch list would post its
         * entire release history — or at least its newest release, which may be
         * years old — into a discussion as though it had just happened. The
         * first poll simply records where we came in.
         */
        if (! $seen) {
            $this->touch($repo, $latest['id'], $latest['tag'], posted: false);
            $result['adopted']++;
            $result['details'][] = "{$repo}: now watching from {$latest['tag']} (nothing posted)";
            return;
        }

        if (($seen->last_release_id ?? null) === $latest['id']) {
            $this->touch($repo, $latest['id'], $latest['tag']);
            return;
        }

        if ($dryRun) {
            $result['details'][] = "{$repo}: WOULD post {$latest['tag']} to discussion {$discussionId}";
            return;
        }

        $notes = $this->notes($client, $repo, $latest['tag']);

        $this->poster->post(
            $botUserId,
            $discussionId,
            trim(basename($repo).' '.$latest['tag']),
            $notes,
            $latest['url']
        );

        $this->touch($repo, $latest['id'], $latest['tag'], posted: true);
        $result['posted']++;
        $result['details'][] = "{$repo}: posted {$latest['tag']} to discussion {$discussionId}";
    }

    /**
     * The newest entry in a GitHub releases atom feed.
     *
     * @return array{id:string, tag:string, url:string}|null
     */
    private function newestEntry(string $xml): ?array
    {
        // Entries are newest-first in GitHub's feed, so the first is the one.
        $previous = libxml_use_internal_errors(true);

        try {
            $feed = simplexml_load_string($xml);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $feed || ! isset($feed->entry[0])) {
            return null;
        }

        $entry = $feed->entry[0];
        $id    = trim((string) $entry->id);

        if ($id === '') {
            return null;
        }

        $url = '';
        foreach ($entry->link as $link) {
            $href = (string) $link['href'];
            if ($href !== '') {
                $url = $href;
                break;
            }
        }

        /*
         * The tag comes from the LINK, not the title. A release title is
         * free text — plenty are "Bug fixes" or empty — while the link always
         * ends in the tag, which is what the REST call needs.
         */
        $tag = $url !== '' ? rawurldecode(basename(parse_url($url, PHP_URL_PATH) ?: '')) : '';

        if ($tag === '') {
            $tag = trim((string) $entry->title);
        }

        return ['id' => $id, 'tag' => $tag, 'url' => $url];
    }

    /** The release body in its original markdown, or a fallback line. */
    private function notes(Client $client, string $repo, string $tag): string
    {
        if ($tag === '') {
            return '';
        }

        try {
            $token = trim((string) $this->settings->get('ernestdefoe-github-release-bot.api_token'));
            $headers = ['Accept' => 'application/vnd.github+json'];

            // Optional, and only worth setting for a long watch list — detection
            // never touches this API, so the default 60/hour covers occasional
            // releases across many repositories.
            if ($token !== '') {
                $headers['Authorization'] = 'Bearer '.$token;
            }

            $res = $client->get(sprintf(self::API, $repo, rawurlencode($tag)), ['headers' => $headers]);
            $body = json_decode((string) $res->getBody(), true);

            return is_array($body) ? trim((string) ($body['body'] ?? '')) : '';
        } catch (Throwable $e) {
            /*
             * A missing or rate-limited body must not cost us the announcement.
             * The tag and the link are the part people actually need; the notes
             * are a bonus we can do without for one release.
             */
            $this->log->info('[github-release-bot] notes unavailable', [
                'repo' => $repo, 'tag' => $tag, 'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    private function touch(string $repo, ?string $id, ?string $tag, bool $posted = false): void
    {
        $row = [
            'last_release_id' => $id,
            'last_tag'        => $tag,
            'last_checked_at' => Carbon::now(),
            'failures'        => 0,
            'last_error'      => null,
        ];

        if ($posted) {
            $row['last_posted_at'] = Carbon::now();
        }

        $this->db->table('github_release_bot_seen')->updateOrInsert(['repo' => $repo], $row);
    }

    private function recordFailure(string $repo, string $message): void
    {
        $existing = $this->db->table('github_release_bot_seen')->where('repo', $repo)->first();

        $this->db->table('github_release_bot_seen')->updateOrInsert(['repo' => $repo], [
            'last_checked_at' => Carbon::now(),
            'failures'        => (int) ($existing->failures ?? 0) + 1,
            'last_error'      => mb_substr($message, 0, 255),
        ]);
    }
}
