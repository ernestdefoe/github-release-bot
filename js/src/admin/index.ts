import app from 'flarum/admin/app';
import Admin from 'flarum/common/extenders/Admin';

const t = (key: string) => app.translator.trans('ernestdefoe-github-release-bot.admin.settings.' + key);

export default [
  new Admin()
    .setting(() => ({
      setting: 'ernestdefoe-github-release-bot.webhook_secret',
      label: t('webhook_secret_label'),
      help: t('webhook_secret_help'),
      type: 'text',
      placeholder: t('webhook_secret_placeholder'),
    }))
    .setting(() => ({
      setting: 'ernestdefoe-github-release-bot.bot_user_id',
      label: t('bot_user_id_label'),
      help: t('bot_user_id_help'),
      type: 'number',
      placeholder: '1',
      min: 1,
    }))
    .setting(() => ({
      setting: 'ernestdefoe-github-release-bot.repo_map',
      label: t('repo_map_label'),
      help: t('repo_map_help'),
      type: 'textarea',
      placeholder: '{"mosaic":42,"recruiting":43,"social-groups":44}',
    }))
    .setting(() => ({
      setting: 'ernestdefoe-github-release-bot.watch_map',
      label: t('watch_map_label'),
      help: t('watch_map_help'),
      type: 'textarea',
      placeholder: '{"flarum/framework":50,"fof/upload":51}',
    }))
    .setting(() => ({
      setting: 'ernestdefoe-github-release-bot.api_token',
      label: t('api_token_label'),
      help: t('api_token_help'),
      type: 'text',
      placeholder: t('api_token_placeholder'),
    })),
];
