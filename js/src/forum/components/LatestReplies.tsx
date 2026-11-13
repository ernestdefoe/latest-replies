import app from 'flarum/forum/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/helpers/humanTime';
import stringToColor from 'flarum/common/utils/stringToColor';
import type Mithril from 'mithril';
import * as store from '../store';
import * as placement from '../placement';
import type { Row } from '../store';

export interface LatestRepliesAttrs extends ComponentAttrs {
  /** Who placed it: stock-sidebar, stock-top, stock-phone, stock-discussion, bespoke, fof, page-builder. */
  host: string;
  /** Rows with "X replied to", excerpt and tag (top of the list, wide zones). */
  wide?: boolean;
  limit?: number | string;
  /** The tag's slug. 🚨 Never an attribute named `tag`: it never arrives. */
  tagSlug?: string | null;
  title?: string;
  /** Phones: a Hide/Show switch, remembered in this browser. */
  collapsible?: boolean;
  /** No heading: the host draws its own (FoF Widgets). */
  bare?: boolean;
}

const t = (key: string, params: Record<string, unknown> = {}) => app.translator.trans(`ernestdefoe-latest-replies.${key}`, params);

const HIDDEN_KEY = 'latestRepliesHidden';

function readHidden(): boolean {
  try {
    return window.localStorage.getItem(HIDDEN_KEY) === '1';
  } catch (e) {
    return false;
  }
}

function writeHidden(hidden: boolean): void {
  try {
    if (hidden) window.localStorage.setItem(HIDDEN_KEY, '1');
    else window.localStorage.removeItem(HIDDEN_KEY);
  } catch (e) {
    // Private windows: the switch still works for this page.
  }
}

export default class LatestReplies extends Component<LatestRepliesAttrs> {
  private host!: string;
  private tagSlug: string | null = null;
  private limit = 5;
  private hidden = false;

  oninit(vnode: Mithril.Vnode<LatestRepliesAttrs, this>) {
    super.oninit(vnode);
    // Read once: reading attrs at teardown could release a mount that was never claimed.
    this.host = this.attrs.host;
    this.hidden = !!this.attrs.collapsible && readHidden();
    this.subscribe();
  }

  oncreate(vnode: Mithril.VnodeDOM<LatestRepliesAttrs, this>) {
    super.oncreate(vnode);
    placement.claim(this.host);
    this.fallback();
  }

  onupdate(vnode: Mithril.VnodeDOM<LatestRepliesAttrs, this>) {
    super.onupdate(vnode);
    this.fallback();
  }

  /**
   * The top-of-list copy is for phones, and for any page whose layout has no
   * visible index sidebar (Bespoke's, for one): there the sidebar copy is drawn
   * but never seen, so this one takes over. Hooks run after the whole draw, so
   * the sidebar copy is already in (or out of) the page.
   */
  private fallback() {
    if (this.host !== 'stock-phone' || !this.element) return;
    const sidebar = document.querySelector('.LatestReplies--stock-sidebar');
    this.element.classList.toggle('LatestReplies--fallback', !sidebar || sidebar.getClientRects().length === 0);
  }

  onbeforeupdate(vnode: Mithril.VnodeDOM<LatestRepliesAttrs, this>) {
    super.onbeforeupdate(vnode);
    // Moving between tag pages reuses this component: follow the new tag.
    if ((vnode.attrs.tagSlug || null) !== this.tagSlug || store.limitOf(vnode.attrs.limit) !== this.limit) {
      store.release(this.tagSlug, this.limit);
      this.subscribe(vnode.attrs);
    }
  }

  onremove(vnode: Mithril.VnodeDOM<LatestRepliesAttrs, this>) {
    super.onremove(vnode);
    placement.release(this.host);
    store.release(this.tagSlug, this.limit);
  }

  private subscribe(attrs: LatestRepliesAttrs = this.attrs) {
    this.tagSlug = attrs.tagSlug || null;
    this.limit = store.limitOf(attrs.limit);
    store.claim(this.tagSlug, this.limit);
  }

  view() {
    // A stock copy steps aside while a widget host shows the widget.
    if (placement.isStock(this.host) && placement.hostedElsewhere()) return null;

    const rows = store.rowsOf(this.tagSlug, this.limit);
    const wide = !!this.attrs.wide;
    const title = this.attrs.title || t('lib.title');

    return (
      <section className={'LatestReplies' + (wide ? ' LatestReplies--wide' : '') + ` LatestReplies--${this.host}`} aria-label={String(title)}>
        {this.attrs.bare ? null : (
          <div className="LatestReplies-header">
            <h3 className="LatestReplies-title">{title}</h3>
            {this.attrs.collapsible ? (
              <button
                type="button"
                className="Button Button--link LatestReplies-toggle"
                aria-expanded={this.hidden ? 'false' : 'true'}
                aria-label={String(t(this.hidden ? 'forum.show_label' : 'forum.hide_label'))}
                onclick={() => {
                  this.hidden = !this.hidden;
                  writeHidden(this.hidden);
                }}
              >
                {t(this.hidden ? 'forum.show' : 'forum.hide')}
              </button>
            ) : null}
          </div>
        )}
        {this.hidden ? null : this.body(rows, wide)}
      </section>
    );
  }

  private body(rows: Row[] | null, wide: boolean) {
    if (rows === null) return <LoadingIndicator size="small" display="block" />;
    if (!rows.length) return <p className="LatestReplies-empty">{t('forum.empty')}</p>;

    return <ul className="LatestReplies-list">{rows.map((row) => (wide ? this.wideRow(row) : this.compactRow(row)))}</ul>;
  }

  private compactRow(row: Row) {
    return (
      <li key={`${row.discussionId}-${row.number}`} className="LatestReplies-item">
        <Link className="LatestReplies-link" href={this.href(row)}>
          {this.avatar(row)}
          <span className="LatestReplies-text">
            <span className="LatestReplies-discussion">{row.discussionTitle}</span>
            <span className="LatestReplies-meta LatestReplies-meta--line">
              {this.name(row)} · {row.createdAt ? humanTime(row.createdAt) : null}
            </span>
          </span>
        </Link>
      </li>
    );
  }

  private wideRow(row: Row) {
    const name = <strong className="LatestReplies-author">{this.name(row)}</strong>;

    return (
      <li key={`${row.discussionId}-${row.number}`} className="LatestReplies-item">
        <Link className="LatestReplies-link" href={this.href(row)}>
          {this.avatar(row)}
          <span className="LatestReplies-text">
            <span className="LatestReplies-line">
              {t(row.number <= 1 ? 'forum.started' : 'forum.replied_to', { name })}{' '}
              <strong className="LatestReplies-discussion">{row.discussionTitle}</strong>
            </span>
            {row.excerpt ? <span className="LatestReplies-excerpt">{row.excerpt}</span> : null}
            <span className="LatestReplies-meta">
              {row.tag ? (
                <span className="LatestReplies-tag">
                  <span className="LatestReplies-tagDot" style={row.tag.color ? { '--tag-color': row.tag.color } : undefined} aria-hidden="true" />
                  {row.tag.name}
                </span>
              ) : null}
              {row.createdAt ? humanTime(row.createdAt) : null}
            </span>
          </span>
        </Link>
      </li>
    );
  }

  private href(row: Row): string {
    return app.route('discussion.near', { id: row.slug, near: row.number });
  }

  private name(row: Row): string {
    return row.user?.displayName || String(app.translator.trans('ernestdefoe-latest-replies.forum.deleted_user', {}, true));
  }

  private avatar(row: Row) {
    const name = this.name(row);
    if (row.user?.avatarUrl) return <img className="Avatar LatestReplies-avatar" src={row.user.avatarUrl} alt="" loading="lazy" />;

    return (
      <span className="Avatar LatestReplies-avatar" style={{ '--avatar-bg': row.user?.color || '#' + stringToColor(name) }} aria-hidden="true">
        {name.charAt(0).toUpperCase()}
      </span>
    );
  }
}
