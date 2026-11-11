import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';
import { registerEditorWidgets } from '../common/fofWidgets';
import TagRules from './TagRules';

const EXT = 'ernestdefoe-latest-replies';
const t = (key: string) => app.translator.trans(`${EXT}.admin.settings.${key}`, {}, true);
const setting = (key: string) => `${EXT}.${key}`;

export const extend = [
  new Extend.Admin()
    .setting(
      () => ({
        setting: setting('placement'),
        type: 'select',
        label: t('placement'),
        help: t('placement_help'),
        options: { sidebar: t('placement_sidebar'), top: t('placement_top'), none: t('placement_none') },
        default: 'sidebar',
      }),
      100
    )
    .setting(() => ({ setting: setting('discussion_page'), type: 'boolean', label: t('discussion_page') }), 90)
    .setting(() => ({ setting: setting('count'), type: 'number', label: t('count'), help: t('count_help'), min: 3, max: 20 }), 80)
    .setting(
      () => ({
        setting: setting('mode'),
        type: 'select',
        label: t('mode'),
        help: t('mode_help'),
        options: { discussion: t('mode_discussion'), post: t('mode_post') },
        default: 'discussion',
      }),
      70
    )
    .setting(() => ({ setting: setting('first_posts'), type: 'boolean', label: t('first_posts') }), 60)
    .setting(() => ({ setting: setting('excerpt'), type: 'boolean', label: t('excerpt'), help: t('excerpt_help') }), 50)
    .setting(() => ({ setting: setting('excerpt_length'), type: 'number', label: t('excerpt_length'), min: 40, max: 300 }), 40)
    .customSetting(function (this: any) {
      return 'flarum-tags' in ((globalThis as any).flarum?.extensions || {}) ? <TagRules page={this} /> : null;
    }, 30),
];

app.initializers.add(EXT, () => {
  registerEditorWidgets(app, EXT, [{ key: 'latestReplies', icon: 'fas fa-comments', title: () => app.translator.trans(`${EXT}.lib.title`) }]);
});
