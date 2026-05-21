import Admin from 'flarum/common/extenders/Admin';

export default [
  new Admin()
    .setting(() => ({
      setting:     'ernestdefoe-github-release-bot.webhook_secret',
      label:       'GitHub Webhook Secret',
      help:        'Shared HMAC secret. Paste this same value into every GitHub repo\'s webhook configuration.',
      type:        'text',
      placeholder: 'long random string (e.g. 64 hex chars from `openssl rand -hex 32`)',
    }))
    .setting(() => ({
      setting:     'ernestdefoe-github-release-bot.bot_user_id',
      label:       'Bot User ID',
      help:        'Flarum user ID that will author the release replies (typically your admin account — usually 1).',
      type:        'number',
      placeholder: '1',
      min:         1,
    }))
    .setting(() => ({
      setting:     'ernestdefoe-github-release-bot.repo_map',
      label:       'Repository → Discussion Map',
      help:        'JSON object mapping GitHub repo name to discussion ID. Example: {"mosaic":42,"recruiting":43}',
      type:        'textarea',
      placeholder: '{"mosaic":42,"recruiting":43,"social-groups":44}',
    })),
];
