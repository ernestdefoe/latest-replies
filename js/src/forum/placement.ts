/**
 * Which hosts are showing the widget RIGHT NOW.
 *
 * 🚨 "A host is installed" is not "a host shows it": a forum with Bespoke or
 * FoF Widgets installed but no Latest Replies placed must still get the stock
 * copy (Garrison shipped that bug: the widget was on no page at all). So the
 * stock copies stand down only while a widget host has one mounted, counted
 * from real mounts. The failure direction is two copies, never zero.
 */
const mounts = new Map<string, number>();

const isStock = (host: string) => host.startsWith('stock');

export function claim(host: string): void {
  mounts.set(host, (mounts.get(host) || 0) + 1);
  // oncreate runs after the draw that decided the stock copy; redraw on the 0 → 1 change only.
  if (!isStock(host) && mounts.get(host) === 1) m.redraw();
}

export function release(host: string): void {
  const n = (mounts.get(host) || 0) - 1;
  if (n > 0) mounts.set(host, n);
  else mounts.delete(host);
  if (!isStock(host) && n <= 0) m.redraw();
}

export function hostedElsewhere(): boolean {
  for (const host of mounts.keys()) if (!isStock(host)) return true;
  return false;
}

export { isStock };
