<?php

namespace ErnestDefoe\GitHubReleaseBot;

use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\ClientInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
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
    private const API = 'https://api.github.com/repos/%s/releases/tags/%s';

    /**
     * At most this many repositories are checked per run, oldest check first,
     * so a long watch list is covered over several runs instead of one.
     */
    private const MAX_REPOS_PER_RUN = 50;

    /**
     * Wall-clock budget for one run. Each repository can cost two requests of
     * up to 15 s, so without a cap a slow GitHub could keep the worker busy
     * past the next half-hourly slot. What is left over goes first next run.
     */
    private const TIME_BUDGET_SECONDS = 600;

    /** A failing repository waits 30 min, 1 h, 2 h... up to a day between tries. */
    private const BACKOFF_MAX_MINUTES = 1440;

    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings,
        protected ReleasePoster $poster,
        protected LoggerInterface $log,
        protected ClientInterface $http,
    ) {
    }

    /** @return array{checked:int, posted:int, adopted:int, failed:int, deferred:int, details:list<string>} */
    public function run(bool $dryRun = false): array
    {
        $botUserId = (int) $this->settings->get('ernestdefoe-github-release-bot.bot_user_id');
        $targets = $this->targets();

        $result = ['checked' => 0, 'posted' => 0, 'adopted' => 0, 'failed' => 0, 'deferred' => 0, 'details' => []];

        if ($targets === [] || $botUserId <= 0) {
            return $result;
        }

        // One query for every repository's state, not one per repository.
        $seen = $this->db->table('github_release_bot_seen')
            ->whereIn('repo', array_keys($targets))
            ->get()
            ->keyBy('repo');

        $now = Carbon::now();
        $deadline = $now->copy()->addSeconds(self::TIME_BUDGET_SECONDS);

        foreach ($this->oldestFirst(array_keys($targets), $seen) as $repo) {
            $row = $seen->get($repo);

            if ($row && $this->backingOff($row, $now)) {
                $result['details'][] = "{$repo}: skipped after {$row->failures} failed checks, retrying later";
            } elseif ($result['checked'] >= self::MAX_REPOS_PER_RUN || Carbon::now()->greaterThanOrEqualTo($deadline)) {
                $result['deferred']++;
                $result['details'][] = "{$repo}: not checked this run (limit reached), first in line next run";
            } else {
                $result['checked']++;
                $this->checkSafely($repo, $targets[$repo], $row, $botUserId, $dryRun, $result);
            }
        }

        return $result;
    }

    /**
     * The watch map's usable entries, `owner/repo` => discussion id.
     *
     * @return array<string, int>
     */
    private function targets(): array
    {
        $map = json_decode((string) $this->settings->get('ernestdefoe-github-release-bot.watch_map'), true);
        $targets = [];

        foreach (is_array($map) ? $map : [] as $repo => $discussionId) {
            $repo = trim((string) $repo);

            if ($repo !== '' && (int) $discussionId > 0 && str_contains($repo, '/')) {
                $targets[$repo] = (int) $discussionId;
            }
        }

        return $targets;
    }

    /**
     * Never-checked first, then the longest-unchecked, so the repositories a
     * capped run left out are the first ones the next run reaches.
     *
     * @param list<string> $repos
     * @return list<string>
     */
    private function oldestFirst(array $repos, Collection $seen): array
    {
        usort($repos, fn (string $a, string $b) => strcmp(
            (string) ($seen->get($a)->last_checked_at ?? ''),
            (string) ($seen->get($b)->last_checked_at ?? '')
        ));

        return $repos;
    }

    /** One repository; a failure is logged and counted, and never stops the run. */
    private function checkSafely(string $repo, int $discussionId, ?object $row, int $botUserId, bool $dryRun, array &$result): void
    {
        try {
            $this->checkOne($repo, $discussionId, $row, $botUserId, $dryRun, $result);
        } catch (Throwable $e) {
            $result['failed']++;
            $result['details'][] = "{$repo}: {$e->getMessage()}";
            $this->recordFailure($repo, (int) ($row->failures ?? 0), $e->getMessage());
            $this->log->warning('[github-release-bot] watch failed', [
                'repo' => $repo, 'error' => $e->getMessage(),
            ]);
        }
    }

    /** A repository that keeps failing is retried less often, not every run. */
    private function backingOff(object $row, Carbon $now): bool
    {
        $failures = (int) ($row->failures ?? 0);

        if ($failures <= 0 || empty($row->last_checked_at)) {
            return false;
        }

        $wait = min(30 * (2 ** min($failures - 1, 6)), self::BACKOFF_MAX_MINUTES);

        return Carbon::parse($row->last_checked_at)->addMinutes($wait)->greaterThan($now);
    }

    private function checkOne(
        string $repo,
        int $discussionId,
        ?object $seen,
        int $botUserId,
        bool $dryRun,
        array &$result
    ): void {
        $xml = (string) $this->http->request('GET', sprintf(self::FEED, $repo))->getBody();

        $latest = $this->newestEntry($xml);

        if (! $latest) {
            // A repository with no releases at all is not an error; it may get
            // one later. Record the check so the row exists and stays fresh.
            $this->touch($repo, null, null);

            return;
        }

        // 🚨 First sight adopts, it does not announce: otherwise adding a repo
        // would post its newest release, maybe years old, as if it were new.
        // A row left only by failed checks has never seen a release: also first sight.
        if (! $seen || ($seen->last_release_id === null && (int) $seen->failures > 0)) {
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

        $this->announce($repo, $discussionId, $botUserId, $latest);
        $result['posted']++;
        $result['details'][] = "{$repo}: posted {$latest['tag']} to discussion {$discussionId}";
    }

    /** @param array{id:string, tag:string, url:string} $latest */
    private function announce(string $repo, int $discussionId, int $botUserId, array $latest): void
    {
        $this->poster->post(
            $botUserId,
            $discussionId,
            trim(basename($repo).' '.$latest['tag']),
            $this->notes($repo, $latest['tag']),
            $latest['url']
        );

        $this->touch($repo, $latest['id'], $latest['tag'], posted: true);
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
        $id = trim((string) $entry->id);

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
    private function notes(string $repo, string $tag): string
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

            $res = $this->http->request('GET', sprintf(self::API, $repo, rawurlencode($tag)), ['headers' => $headers]);
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
            'last_tag' => $tag,
            'last_checked_at' => Carbon::now(),
            'failures' => 0,
            'last_error' => null,
        ];

        if ($posted) {
            $row['last_posted_at'] = Carbon::now();
        }

        $this->db->table('github_release_bot_seen')->updateOrInsert(['repo' => $repo], $row);
    }

    private function recordFailure(string $repo, int $failures, string $message): void
    {
        $this->db->table('github_release_bot_seen')->updateOrInsert(['repo' => $repo], [
            'last_checked_at' => Carbon::now(),
            'failures' => min($failures + 1, 65535),
            'last_error' => mb_substr($message, 0, 255),
        ]);
    }
}
