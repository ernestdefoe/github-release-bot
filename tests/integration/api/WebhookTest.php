<?php

namespace ErnestDefoe\GitHubReleaseBot\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Console\Scheduling\Schedule;
use Laminas\Diactoros\Stream;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

class WebhookTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const SECRET = 'shared-secret';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-github-release-bot');
        $this->setting('ernestdefoe-github-release-bot.webhook_secret', self::SECRET);
        $this->setting('ernestdefoe-github-release-bot.bot_user_id', '2');
        $this->setting('ernestdefoe-github-release-bot.repo_map', json_encode(['ridge' => 1, 'gone' => 99]));

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            Discussion::class => [['id' => 1, 'title' => 'Ridge releases', 'created_at' => Carbon::now()->subDay(), 'user_id' => 1, 'first_post_id' => 1, 'comment_count' => 1, 'last_post_number' => 1, 'participant_count' => 1]],
            Post::class => [['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => Carbon::now()->subDay(), 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>Releases</p></t>']],
        ]);
    }

    private function deliver(string $event, array $payload, ?string $secret = self::SECRET): ResponseInterface
    {
        $body = json_encode($payload);
        $request = $this->request('POST', '/api/github-webhook')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-GitHub-Event', $event)
            ->withBody(new Stream('php://memory', 'w+'));
        $request->getBody()->write($body);
        $request->getBody()->rewind();

        if ($secret !== null) {
            $request = $request->withHeader('X-Hub-Signature-256', 'sha256='.hash_hmac('sha256', $body, $secret));
        }

        return $this->send($request);
    }

    private function release(string $repo, string $action = 'published', string $notes = 'Fixed the thing.'): array
    {
        return ['action' => $action, 'repository' => ['name' => $repo], 'release' => ['tag_name' => '1.2.0', 'body' => $notes, 'html_url' => 'https://github.com/x/'.$repo.'/releases/tag/1.2.0']];
    }

    private function replies(): int
    {
        return $this->database()->table('posts')->where('discussion_id', 1)->where('number', '>', 1)->count();
    }

    #[Test]
    public function an_unconfigured_forum_refuses_every_delivery()
    {
        $this->setting('ernestdefoe-github-release-bot.webhook_secret', '');

        $this->assertSame(503, $this->deliver('release', $this->release('ridge'))->getStatusCode());
        $this->assertSame(0, $this->replies());
    }

    #[Test]
    public function a_delivery_not_signed_with_the_secret_is_refused()
    {
        $this->assertSame(401, $this->deliver('release', $this->release('ridge'), null)->getStatusCode());
        $this->assertSame(401, $this->deliver('release', $this->release('ridge'), 'guessed')->getStatusCode());
        $this->assertSame(0, $this->replies());
    }

    #[Test]
    public function only_a_published_release_of_a_mapped_repository_is_posted()
    {
        $this->assertSame(['pong' => true], json_decode((string) $this->deliver('ping', ['zen' => 'hi'])->getBody(), true));
        $this->assertSame(['ignored' => 'not_release_event'], json_decode((string) $this->deliver('push', [])->getBody(), true));
        $this->assertSame(['ignored' => 'not_published_action'], json_decode((string) $this->deliver('release', $this->release('ridge', 'created'))->getBody(), true));
        $this->assertSame(['ignored' => 'repo_not_mapped', 'repo' => 'other'], json_decode((string) $this->deliver('release', $this->release('other'))->getBody(), true));

        $this->assertSame(0, $this->replies());
    }

    #[Test]
    public function a_release_is_posted_by_the_bot_and_the_thread_is_brought_up_to_date()
    {
        $response = $this->deliver('release', $this->release('ridge'));

        $this->assertSame(200, $response->getStatusCode());
        $post = $this->database()->table('posts')->where('discussion_id', 1)->where('number', 2)->first();
        $this->assertNotNull($post);
        $this->assertEquals(2, $post->user_id);
        $this->assertStringContainsString('ridge 1.2.0', $post->content);
        $this->assertStringContainsString('Fixed the thing.', $post->content);
        $this->assertStringContainsString('https://github.com/x/ridge/releases/tag/1.2.0', $post->content);

        $discussion = $this->database()->table('discussions')->where('id', 1)->first();
        $this->assertEquals(2, $discussion->comment_count);
        $this->assertEquals(2, $discussion->last_post_number);
        $this->assertEquals(2, $discussion->last_posted_user_id);
    }

    #[Test]
    public function a_release_without_notes_says_so()
    {
        $this->deliver('release', $this->release('ridge', 'published', '   '));

        $this->assertStringContainsString('No release notes provided.', $this->database()->table('posts')->where('discussion_id', 1)->where('number', 2)->value('content'));
    }

    #[Test]
    public function a_missing_discussion_is_reported_not_posted()
    {
        $response = $this->deliver('release', $this->release('gone'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(['error' => 'discussion_not_found', 'discussion_id' => 99], json_decode((string) $response->getBody(), true));
        $this->assertSame(0, $this->database()->table('posts')->where('discussion_id', 99)->count());
    }

    #[Test]
    public function a_bot_user_that_does_not_exist_is_reported()
    {
        $this->setting('ernestdefoe-github-release-bot.bot_user_id', '999');

        $this->assertSame(503, $this->deliver('release', $this->release('ridge'))->getStatusCode());
        $this->assertSame(0, $this->replies());
    }

    #[Test]
    public function watched_repositories_are_polled_every_half_hour()
    {
        $events = $this->app()->getContainer()->make(Schedule::class)->events();
        $poll = array_values(array_filter($events, fn ($e) => str_contains((string) $e->command, 'github-release-bot:poll')));

        $this->assertCount(1, $poll);
        $this->assertSame('*/30 * * * *', $poll[0]->expression);
        $this->assertTrue($poll[0]->withoutOverlapping);
    }
}
