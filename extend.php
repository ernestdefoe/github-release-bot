<?php

use ErnestDefoe\GitHubReleaseBot\Api\Controller\HandleGitHubWebhookController;
use ErnestDefoe\GitHubReleaseBot\Console\PollCommand;
use ErnestDefoe\GitHubReleaseBot\Provider\HttpClientProvider;
use Flarum\Extend;

return [
    /*
     * A plain PSR-15 handler, not a JSON:API resource: GitHub posts its own
     * payload format, and the HMAC signature must be checked against the raw
     * body before anything parses it. It is a callback from GitHub, not a
     * resource that forum clients list, read or edit.
     */
    (new Extend\Routes('api'))
        ->post('/github-webhook', 'ernestdefoe.github-release-bot.webhook', HandleGitHubWebhookController::class),

    (new Extend\Csrf())
        ->exemptRoute('ernestdefoe.github-release-bot.webhook'),

    new Extend\Locales(__DIR__.'/locale'),

    // Caps on one watch run; Watcher clamps them so the cap can't be removed.
    (new Extend\Settings())
        ->default('ernestdefoe-github-release-bot.watch_max_repos', 50)
        ->default('ernestdefoe-github-release-bot.watch_time_budget', 10),

    /*
     * Watch mode: announce releases from repositories we do NOT own.
     *
     * The webhook above is unchanged and remains the path for your own repos —
     * it is instant, and it is the only one that can be. This runs alongside it
     * for everyone else's, where no webhook can be installed.
     *
     * Half-hourly: releases are not urgent enough to poll harder, and the feed
     * costs nothing either way.
     */
    (new Extend\ServiceProvider())
        ->register(HttpClientProvider::class),

    (new Extend\Console())
        ->command(PollCommand::class)
        ->schedule(PollCommand::class, function ($event) {
            $event->everyThirtyMinutes()->withoutOverlapping();
        }),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),
];
