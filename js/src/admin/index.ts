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
      label:       'Repository → Discussion Map (your own repos, via webhook)',
      help:        'JSON object mapping GitHub repo name to discussion ID. Example: {"mosaic":42,"recruiting":43}',
      type:        'textarea',
      placeholder: '{"mosaic":42,"recruiting":43,"social-groups":44}',
    }))
    .setting(() => ({
      setting:     'ernestdefoe-github-release-bot.watch_map',
      label:       'Watched Repositories → Discussion Map (anyone\'s repos, checked every 30 min)',
      help:
        'For repositories you do NOT own, where you cannot add a webhook. Use the full owner/repo path. '
        + 'Example: {"flarum/framework":50,"fof/upload":51}. '
        + 'A newly added repository is adopted silently — its current release is recorded and nothing is posted, '
        + 'so adding one never dumps old releases into a discussion. Only releases published after that are announced.',
      type:        'textarea',
      placeholder: '{"flarum/framework":50,"fof/upload":51}',
    }))
    .setting(() => ({
      setting:     'ernestdefoe-github-release-bot.api_token',
      label:       'GitHub Token (optional)',
      help:
        'Only needed for a long watch list. Release detection uses public feeds and never touches the API rate limit; '
        + 'a token is used solely to fetch release notes, and the unauthenticated limit of 60/hour is plenty for '
        + 'occasional releases. A read-only token with no scopes is enough.',
      type:        'text',
      placeholder: 'ghp_… (leave blank unless you hit rate limits)',
    })),
];
