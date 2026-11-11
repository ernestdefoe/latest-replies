import app from 'flarum/forum/app';

export interface Row {
  id: number | null;
  number: number;
  createdAt: string | null;
  discussionId: number;
  discussionTitle: string | null;
  slug: string;
  url: string;
  user: { displayName: string; slug: string; avatarUrl: string | null; color: string | null } | null;
  tag: { name: string; color: string | null; slug: string } | null;
  excerpt: string | null;
  spoilerShield?: { hidden: boolean; label?: string };
}

interface Entry {
  rows: Row[] | null;
  busy: boolean;
  at: number;
  mounts: number;
}

/*
 * One list per (tag, size), shared by every host that shows it: the stock
 * sidebar and Bespoke's sidebar zone on one page cost one request. Module
 * level, so a host that remounts (Bespoke re-renders its zones) finds its rows.
 */
const entries = new Map<string, Entry>();
const STALE = 60_000;

const keyOf = (tag: string | null, limit: number) => `${tag || ''}|${limit}`;

export function limitOf(n: unknown): number {
  const v = parseInt(String(n), 10) || app.forum.attribute<number>('latestRepliesCount') || 5;
  return Math.max(3, Math.min(20, v));
}

export function claim(tag: string | null, limit: number): Entry {
  const key = keyOf(tag, limit);
  let entry = entries.get(key);
  if (!entry) entries.set(key, (entry = { rows: null, busy: false, at: 0, mounts: 0 }));
  entry.mounts++;
  if (!entry.at || Date.now() - entry.at > STALE) load(key, entry);
  return entry;
}

export function rowsOf(tag: string | null, limit: number): Row[] | null {
  return entries.get(keyOf(tag, limit))?.rows ?? null;
}

export function release(tag: string | null, limit: number): void {
  const entry = entries.get(keyOf(tag, limit));
  if (entry) entry.mounts = Math.max(0, entry.mounts - 1);
}

function load(key: string, entry: Entry): void {
  if (entry.busy) return;
  entry.busy = true;
  const [tag, limit] = key.split('|');
  const params: Record<string, string> = { limit };
  if (tag) params.tag = tag;

  app
    .request<{ rows: Row[] }>({ method: 'GET', url: app.forum.attribute('apiUrl') + '/latest-replies', params })
    .then((body) => {
      entry.rows = Array.isArray(body?.rows) ? body.rows : [];
      entry.at = Date.now();
    })
    // Keep what was shown; an empty list only if there never was one.
    .catch(() => (entry.rows ??= []))
    .finally(() => {
      entry.busy = false;
      m.redraw();
    });
}

/** Lists on screen ask again; the rest are asked when next shown. */
export function refresh(): void {
  entries.forEach((entry, key) => {
    if (entry.mounts > 0) load(key, entry);
    else entry.at = 0;
  });
}

let timer = 0;

/** Many tabs hear the same push: spread their asks over a few seconds. */
export function refreshSoon(): void {
  if (document.hidden) {
    entries.forEach((entry) => (entry.at = 0));
    return;
  }
  clearTimeout(timer);
  timer = window.setTimeout(refresh, 1000 + Math.random() * 3000);
}

/** Back on the tab after a while: anything older than a minute is asked again. */
export function refreshStale(): void {
  if (document.hidden) return;
  entries.forEach((entry, key) => {
    if (entry.mounts > 0 && Date.now() - entry.at > STALE) load(key, entry);
  });
}
