import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import type ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';
import LatestReplies from './components/LatestReplies';
import { currentTagSlug, registerHosts } from './hosts';
import { refreshSoon, refreshStale } from './store';

export { default as LatestReplies } from './components/LatestReplies';

/*
 * The stock spots: the index sidebar (top of the list on phones, where the
 * sidebar has no room), or the top of the list, and optionally beside a
 * discussion. Each steps aside while a widget host shows the widget.
 */

// flarum/realtime's events that can change someone's list. They only say "ask again".
const EVENTS = [
  'Flarum\\Post\\Event\\Posted',
  'Flarum\\Discussion\\Event\\Started',
  'revisedEvent',
  'discussionRenamed',
  'removed',
  'postHidden',
  'discussionHidden',
  'postRestored',
];

function listenLive(): void {
  const flarum = (globalThis as any).flarum;
  if (!flarum?.extensions || !('flarum-realtime' in flarum.extensions)) return;
  try {
    const rt = flarum.reg.get('flarum-realtime', 'forum/RealtimeState');
    const bind = (channel: any) => EVENTS.forEach((event) => channel.bind(event, refreshSoon));
    rt?.onPublicChannelReady?.(bind);
    rt?.onUserChannelReady?.(bind);
  } catch (e) {
    // No realtime: the list is asked again when the tab comes back after a minute.
  }
}

app.initializers.add('ernestdefoe-latest-replies', () => {
  const placement = () => app.forum.attribute<string>('latestRepliesPlacement') || 'sidebar';

  // String form: IndexSidebar is in a lazy chunk, and extending an imported copy patches one core never renders.
  extend('flarum/forum/components/IndexSidebar', 'items', function (items: ItemList<Mithril.Children>) {
    if (placement() !== 'sidebar') return;
    items.add('latestReplies', <LatestReplies host="stock-sidebar" tagSlug={currentTagSlug()} />, -50);
  });

  extend('flarum/forum/components/IndexPage', 'contentItems', function (items: ItemList<Mithril.Children>) {
    const where = placement();
    if (where === 'top') {
      items.add('latestReplies', <LatestReplies host="stock-top" wide tagSlug={currentTagSlug()} />, 110);
    } else if (where === 'sidebar') {
      // Phones only (CSS): the sidebar folds into a menu there.
      items.add('latestReplies', <LatestReplies host="stock-phone" collapsible tagSlug={currentTagSlug()} />, 110);
    }
  });

  extend('flarum/forum/components/DiscussionPage', 'sidebarItems', function (items: ItemList<Mithril.Children>) {
    if (!app.forum.attribute('latestRepliesDiscussionPage')) return;
    items.add('latestReplies', <LatestReplies host="stock-discussion" />, -100);
  });

  // After every initializer (Page Builder exposes its registry in its own), before the first draw.
  override(app, 'mount', function (original: (...args: any[]) => unknown, ...args: any[]) {
    registerHosts();
    listenLive();
    document.addEventListener('visibilitychange', refreshStale);
    return original(...args);
  });
});
