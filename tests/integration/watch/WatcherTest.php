<?php

namespace ErnestDefoe\GitHubReleaseBot\Tests\integration\watch;

use Carbon\Carbon;
use ErnestDefoe\GitHubReleaseBot\Watcher;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;

class WatcherTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    /** @var list<string> */
    private array $requested = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-github-release-bot');
        $this->setting('ernestdefoe-github-release-bot.bot_user_id', '2');

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            Discussion::class => [['id' => 1, 'title' => 'Watched', 'created_at' => Carbon::now()->subDay(), 'user_id' => 1, 'first_post_id' => 1, 'comment_count' => 1, 'last_post_number' => 1, 'participant_count' => 1]],
            Post::class => [['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => Carbon::now()->subDay(), 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>Releases</p></t>']],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function feed(string $repo, string $tag, ?string $href = null): string
    {
        $href ??= "https://github.com/{$repo}/releases/tag/{$tag}";

        return '<?xml version="1.0" encoding="UTF-8"?><feed xmlns="http://www.w3.org/2005/Atom">'
            ."<entry><id>tag:github.com,2008:Repository/1/{$tag}</id><title>{$tag}</title>"
            .'<link rel="alternate" type="text/html" href="'.htmlspecialchars($href).'"/></entry></feed>';
    }

    /** A Watcher whose GitHub is $respond, recording every URL it asks for. */
    private function watcher(callable $respond): Watcher
    {
        $handler = function (RequestInterface $request) use ($respond) {
            $this->requested[] = (string) $request->getUri();

            return \GuzzleHttp\Promise\Create::promiseFor($respond($request));
        };

        $client = new Client(['handler' => HandlerStack::create($handler)]);

        return $this->app()->getContainer()->make(Watcher::class, ['http' => $client]);
    }

    private function seen(string $repo, array $row): void
    {
        $this->database()->table('github_release_bot_seen')->insert(['repo' => $repo] + $row);
    }

    private function replies(): \Illuminate\Support\Collection
    {
        return $this->database()->table('posts')->where('discussion_id', 1)->where('number', '>', 1)->pluck('content');
    }

    #[Test]
    public function the_container_gives_the_watcher_a_configured_client()
    {
        $watcher = $this->app()->getContainer()->make(Watcher::class);
        $client = (fn () => $this->http)->call($watcher);

        $this->assertInstanceOf(ClientInterface::class, $client);
        $this->assertSame('flarum-github-release-bot', $client->getConfig('headers')['User-Agent']);
        $this->assertSame(15, $client->getConfig('timeout'));
    }

    #[Test]
    public function first_sight_adopts_and_a_new_release_is_posted_once()
    {
        $this->setting('ernestdefoe-github-release-bot.watch_map', json_encode(['acme/tool' => 1]));
        $tag = '1.0.0';
        $watcher = function () use (&$tag) {
            return $this->watcher(function (RequestInterface $r) use (&$tag) {
                return str_contains((string) $r->getUri(), 'releases.atom')
                    ? new Response(200, [], $this->feed('acme/tool', $tag))
                    : new Response(200, [], json_encode(['body' => 'Notes for '.$tag]));
            });
        };

        $r = $watcher()->run();
        $this->assertSame(1, $r['adopted']);
        $this->assertCount(0, $this->replies());

        $tag = '1.1.0';
        $r = $watcher()->run();
        $this->assertSame(1, $r['posted']);
        $this->assertCount(1, $this->replies());
        $this->assertStringContainsString('Notes for 1.1.0', $this->replies()->first());
        $this->assertStringContainsString('https://github.com/acme/tool/releases/tag/1.1.0', $this->replies()->first());

        $r = $watcher()->run();
        $this->assertSame(0, $r['posted']);
        $this->assertCount(1, $this->replies());
    }

    #[Test]
    public function a_repository_whose_first_check_failed_is_still_adopted_not_announced()
    {
        $this->setting('ernestdefoe-github-release-bot.watch_map', json_encode(['acme/tool' => 1]));

        $this->watcher(fn (RequestInterface $req) => throw new ConnectException('timed out', $req))->run();
        $this->database()->table('github_release_bot_seen')->where('repo', 'acme/tool')
            ->update(['last_checked_at' => Carbon::now()->subHour()]);

        $r = $this->watcher(fn (RequestInterface $req) => new Response(200, [], $this->feed('acme/tool', '0.9.0')))->run();

        $this->assertSame(1, $r['adopted']);
        $this->assertCount(0, $this->replies());
    }

    #[Test]
    public function a_feed_link_that_is_not_http_is_not_linked()
    {
        $this->setting('ernestdefoe-github-release-bot.watch_map', json_encode(['acme/tool' => 1]));
        $this->seen('acme/tool', ['last_release_id' => 'old', 'last_checked_at' => Carbon::now()->subHour()]);

        $this->watcher(fn (RequestInterface $r) => str_contains((string) $r->getUri(), 'releases.atom')
            ? new Response(200, [], $this->feed('acme/tool', '2.0.0', 'javascript:alert(1)//x/2.0.0'))
            : new Response(200, [], json_encode(['body' => 'Notes'])))->run();

        $this->assertCount(1, $this->replies());
        $this->assertStringNotContainsString('javascript:', $this->replies()->first());
    }

    #[Test]
    public function a_run_stops_at_its_time_budget_and_the_rest_go_first_next_run()
    {
        // Settings first: they only apply if set before the app boots.
        $this->setting('ernestdefoe-github-release-bot.watch_map', json_encode(array_fill_keys(array_map(fn ($i) => "acme/r{$i}", range(1, 5)), 1)));
        foreach (range(1, 5) as $i) {
            // r1 is the most recently checked, r5 the longest ago.
            $this->seen("acme/r{$i}", ['last_release_id' => "tag:github.com,2008:Repository/1/1.0.0", 'last_checked_at' => Carbon::now()->subMinutes(30 + 10 * $i)]);
        }

        // Every request takes "four minutes", so the 10-minute budget runs out
        // after the third repository.
        $start = Carbon::now();
        Carbon::setTestNow($start);
        $r = $this->watcher(function (RequestInterface $req) {
            Carbon::setTestNow(Carbon::now()->addMinutes(4));
            preg_match('#github.com/(acme/r\d)/#', (string) $req->getUri(), $m);

            return new Response(200, [], $this->feed($m[1], '1.0.0'));
        })->run();

        $this->assertSame(3, $r['checked']);
        $this->assertSame(2, $r['deferred']);
        $this->assertSame(['acme/r5', 'acme/r4', 'acme/r3'], array_map(fn ($u) => preg_replace('#.*github.com/(acme/r\d)/.*#', '$1', $u), $this->requested));

        $this->requested = [];
        $this->watcher(fn (RequestInterface $req) => new Response(200, [], $this->feed('acme/x', '1.0.0')))->run();
        $this->assertStringContainsString('acme/r2', $this->requested[0]);
        $this->assertStringContainsString('acme/r1', $this->requested[1]);
    }

    #[Test]
    public function a_failing_repository_is_retried_with_backoff_not_every_run()
    {
        $this->setting('ernestdefoe-github-release-bot.watch_map', json_encode(['acme/down' => 1]));
        $this->seen('acme/down', ['last_release_id' => 'x', 'failures' => 0, 'last_checked_at' => Carbon::now()->subHour()]);

        $fail = fn () => $this->watcher(fn (RequestInterface $req) => throw new ConnectException('timed out', $req));

        $r = $fail()->run();
        $this->assertSame(1, $r['failed']);
        $row = $this->database()->table('github_release_bot_seen')->where('repo', 'acme/down')->first();
        $this->assertEquals(1, $row->failures);

        // Make it a repo with three failures: it waits two hours.
        $this->database()->table('github_release_bot_seen')->where('repo', 'acme/down')
            ->update(['failures' => 3, 'last_checked_at' => Carbon::now()->subHour()]);
        $this->requested = [];
        $r = $fail()->run();
        $this->assertSame(0, $r['checked']);
        $this->assertSame([], $this->requested);

        $this->database()->table('github_release_bot_seen')->where('repo', 'acme/down')
            ->update(['last_checked_at' => Carbon::now()->subHours(3)]);
        $r = $fail()->run();
        $this->assertSame(1, $r['failed']);
        $this->assertEquals(4, $this->database()->table('github_release_bot_seen')->where('repo', 'acme/down')->value('failures'));
    }

    #[Test]
    public function the_state_of_every_repository_is_read_in_one_query()
    {
        $this->setting('ernestdefoe-github-release-bot.watch_map', json_encode(array_fill_keys(array_map(fn ($i) => "acme/r{$i}", range(1, 10)), 1)));
        foreach (range(1, 10) as $i) {
            $this->seen("acme/r{$i}", ['last_release_id' => 'tag:github.com,2008:Repository/1/1.0.0', 'last_checked_at' => Carbon::now()->subHour()]);
        }
        $watcher = $this->watcher(fn (RequestInterface $req) => new Response(200, [], $this->feed('acme/x', '1.0.0')));

        $db = $this->app()->getContainer()->make(\Illuminate\Database\ConnectionInterface::class);
        $db->flushQueryLog();
        $db->enableQueryLog();
        $watcher->run();
        $selects = array_filter($db->getQueryLog(), fn ($q) => str_starts_with(strtolower($q['query']), 'select') && str_contains($q['query'], 'github_release_bot_seen'));

        // updateOrInsert's own existence check is one per write; the read of
        // state before the loop is one query for all ten.
        $this->assertCount(1 + 10, $selects);
    }
}
