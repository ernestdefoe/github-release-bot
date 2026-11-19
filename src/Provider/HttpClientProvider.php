<?php

namespace ErnestDefoe\GitHubReleaseBot\Provider;

use ErnestDefoe\GitHubReleaseBot\Watcher;
use Flarum\Foundation\AbstractServiceProvider;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

/**
 * The HTTP client the Watcher talks to GitHub with.
 *
 * Bound for the Watcher only, so the timeouts and the User-Agent GitHub
 * insists on live in one place, and tests can hand it a mock client.
 */
class HttpClientProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->when(Watcher::class)
            ->needs(ClientInterface::class)
            ->give(fn () => new Client([
                'timeout' => 15,
                'connect_timeout' => 8,
                'headers' => [
                    // GitHub rejects requests without an identifying agent.
                    'User-Agent' => 'flarum-github-release-bot',
                ],
            ]));
    }
}
