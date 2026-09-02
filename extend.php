<?php

use ErnestDefoe\GitHubReleaseBot\Api\Controller\HandleGitHubWebhookController;
use ErnestDefoe\GitHubReleaseBot\Console\PollCommand;
use Flarum\Extend;

return [
    (new Extend\Routes('api'))
        ->post('/github-webhook', 'ernestdefoe.github-release-bot.webhook', HandleGitHubWebhookController::class),

    (new Extend\Csrf())
        ->exemptRoute('ernestdefoe.github-release-bot.webhook'),

    (new Extend\Locales(__DIR__.'/locale')),

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
    (new Extend\Console())
        ->command(PollCommand::class)
        ->schedule('github-release-bot:poll', function ($event) {
            $event->everyThirtyMinutes()->withoutOverlapping();
        }),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),
];
