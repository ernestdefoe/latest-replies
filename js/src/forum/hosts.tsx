import app from 'flarum/forum/app';
import { fof, hasFofWidgets, placed } from '../common/fofWidgets';
import LatestReplies from './components/LatestReplies';

/*
 * Offers the widget to every widget host that is present: fof/forum-widgets-core,
 * Bespoke and Page Builder. None is a build-time import; each is found at runtime,
 * so the bundle is the same with all three installed or none. Registering is not
 * placing: the stock copies stand down only for a mounted copy (placement.ts).
 */

const EXT = 'ernestdefoe-latest-replies';
const TITLE_KEY = 'ernestdefoe-latest-replies.lib.title';

export function registerHosts(): void {
  for (const register of [registerFof, registerBespoke, registerPageBuilder]) {
    try {
      register();
    } catch (e) {
      // A host whose internals moved must not take the forum's JS down with it.
      console.warn('[latest-replies] widget host registration failed', e);
    }
  }
}

function registerFof(): void {
  if (!hasFofWidgets()) return;
  const Widgets = fof('common/extend/Widgets');
  const WidgetBase = fof('common/components/Widget');
  if (!Widgets || !WidgetBase || !(app as any).widgets) return;

  class LatestRepliesWidget extends WidgetBase {
    className() {
      return 'LatestRepliesFofWidget';
    }
    icon() {
      return 'fas fa-comments';
    }
    title() {
      return app.translator.trans(TITLE_KEY);
    }
    content() {
      return <LatestReplies host="fof" bare />;
    }
  }

  new Widgets()
    .add({
      key: 'latestReplies',
      component: LatestRepliesWidget,
      // 🚨 Opt-in: shown only where the admin placed it in FoF's layout editor.
      isDisabled: () => !placed(app, EXT, 'latestReplies'),
      isUnique: true,
      placement: 'end',
      position: 1,
    })
    .extend(app, EXT);
}

function registerBespoke(): void {
  const flarum = (globalThis as any).flarum;
  if (!flarum?.extensions || !('ernestdefoe-bespoke' in flarum.extensions)) return;

  // The queue is drained on every registry lookup, so initializer order doesn't matter.
  // Labels are keys, never trans() output (Bespoke calls string methods on them).
  const queue = ((globalThis as any).BespokeWidgetQueue = (globalThis as any).BespokeWidgetQueue || []);
  queue.push({
    type: 'latest-replies',
    label: TITLE_KEY,
    icon: 'fas fa-comments',
    zones: ['sidebar', 'above-list', 'below-list', 'footer'],
    schema: [
      { key: 'title', type: 'text', label: 'ernestdefoe-latest-replies.lib.widget_title', default: '' },
      { key: 'count', type: 'number', label: 'ernestdefoe-latest-replies.lib.widget_count', default: 5 },
      { key: 'wide', type: 'toggle', label: 'ernestdefoe-latest-replies.lib.widget_wide', default: false },
    ],
    component: {
      view: (vnode: any) => {
        const s = vnode.attrs?.settings || {};
        return <LatestReplies host="bespoke" title={s.title || undefined} limit={s.count} wide={!!s.wide} tagSlug={currentTagSlug()} />;
      },
    },
  });
}

function registerPageBuilder(): void {
  const pb = (app as any).pageBuilder;
  if (!pb?.registerBlock) return;

  // Matches LatestRepliesBlock::type().
  pb.registerBlock('latest-replies', {
    view: (vnode: any) => {
      const s = vnode.attrs?.settings || {};
      return <LatestReplies host="page-builder" title={s.title || undefined} limit={s.count} wide={!!s.wide} />;
    },
  });
}

/** The tag page being viewed, if any (flarum/tags). */
export function currentTagSlug(): string | null {
  if (app.current.get('routeName') !== 'tag') return null;
  const tag = (app as any).currentTag?.();
  return tag?.slug?.() || null;
}
