# Latest Replies: working rules

- **Commit straight to `main` and push. Never open a pull request on this repo.** Commit subjects are conventional (`feat:`, `fix:`); the release drafter bumps from the subject.
- `js/dist` is committed and is what forums load. After any change under `js/src`, run `npm run build` in `js/` (from a fresh `npm ci`) and commit the bundle; CI fails if it is stale.
- Publishing a release is Ernest's click. Nothing is announced until he says so. Every change goes to the Command Center for review first.

## Design rules that exist for a reason

- **We never query posts ourselves.** `Feed` asks Flarum's API (`/discussions` or `/posts`) as the viewer, through the whole API pipe, so every visibility scope and every other extension's redaction (Spoiler Shield, Velvet Rope, Byobu, Approval…) has already run. Our own rules only take more away (`Feed::shows`): hidden and unapproved posts (a moderator's answer still has them), hidden discussions, Byobu's private discussions (never listed, not even to participants).
- **Excerpts are an allow-list** (`Excerpt`): only the text of p/strong/em/a/li/headings… in the contentHtml the API gave this viewer. Spoilers, code, quotes, embeds, details and unknown elements are dropped whole. Off by default.
- **The answer is plain JSON shaped for other gates**: each row has `discussionId` + `discussionTitle`, a `/d/{id}` `url`, `slug` and `excerpt`, the keys Spoiler Shield's generic scrub looks for.
- **Only guests are cached** (one answer per tag and size, 60 s, forgotten on any post/discussion change or settings save by bumping a version key). Members are asked live: visibility is per user, so a cache shared by group could leak.
- **Lean**: in per-discussion mode the last post is included only when excerpts are on (every extension adds queries to a post's payload; the discussion's `lastPostNumber`/`lastPostedAt` are enough). Queries are constant in the row count (`~/github/scopes/latest-replies/measure.php`).
- **Placement counts real mounts** (`placement.ts`): stock copies (`stock-*` hosts) stand down only while a widget host (Bespoke, FoF, Page Builder) has one mounted. Installed is not placed. The top-of-list `stock-phone` copy shows on phones and wherever the sidebar copy isn't actually visible (Bespoke's layout hides Flarum's sidebar).
- FoF widgets are opt-in (`isDisabled: !placed()`, admin `registerEditorWidgets`). Bespoke via `BespokeWidgetQueue` with key labels. Page Builder: server block (`Widget\LatestRepliesBlock`, resolve returns nothing) + `app.pageBuilder.registerBlock`, registered in an `app.mount` override after every initializer.
- Realtime only says "ask again" (Posted, Started, revised, renamed, removed, hidden, restored), debounced 1–4 s. Note: flarum/realtime pushes replies on members' own channels only, so guests refresh on tab focus.
- extend.php uses string literals for setting keys. A JS component attribute named `tag` never arrives (`tagSlug`).

## Testing

- `composer test:setup && composer test` (SQLite, and MySQL with `DB_DRIVER=mysql DB_PREFIX=dev_`; check `vendor/flarum/testing/src/integration/tmp/config.php` says mysql), `composer analyse:phpstan`.
- `cd js && npm ci && npm run build && npm run format-check`.
- Local forum `~/Herd/lrdev` (prefix `lr_`); seed, probe (rows per viewer), measure (queries) and puppeteer shots in `~/github/scopes/latest-replies`.
