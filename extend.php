<?php

use ErnestDefoe\GitHubReleaseBot\Api\Controller\HandleGitHubWebhookController;
use Flarum\Extend;

return [
    (new Extend\Routes('api'))
        ->post('/github-webhook', 'ernestdefoe.github-release-bot.webhook', HandleGitHubWebhookController::class),

    (new Extend\Csrf())
        ->exemptRoute('ernestdefoe.github-release-bot.webhook'),

    (new Extend\Locales(__DIR__.'/locale')),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),
];
