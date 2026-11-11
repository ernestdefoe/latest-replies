<?php

namespace ErnestDefoe\LatestReplies;

use Flarum\Api\Client;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Http\UrlGenerator;
use Flarum\Post\Post;
use Flarum\User\User;
use Flarum\Locale\LocaleManager;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The rows of the widget, for one viewer.
 *
 * 🚨 Never a query of our own for content: the rows come from Flarum's API, so
 * whatever any extension hides or redacts there (visibility scopes, Byobu,
 * Approval, Spoiler Shield, Velvet Rope…) is already gone. A member is asked as
 * themselves; the guest answer every guest shares is asked as a guest who is
 * nobody (StatelessApi: no session, cookies, headers or IP), so it is the
 * most-redacted view, never one visitor's. Our own rules only take more away:
 * private discussions, hidden and unapproved posts (a moderator's answer still
 * has them), replies by members the viewer ignores (fof/ignore-users), excerpts
 * by allow-list. The answer is plain JSON shaped so that gates which scrub other
 * extensions' JSON (discussionId + discussionTitle, a /d/{id} url) find it.
 *
 * Caches, all forgotten when anything that can change a list changes (a new
 * cache version): guests share one answer per tag and size for a minute, and
 * while one request rebuilds it after a change the others are served the
 * previous one; a member's own answer is kept 20 seconds, so a burst of
 * Realtime pushes costs each member one rebuild.
 */
class Feed
{
    private const VERSION = 'ernestdefoe-latest-replies.v';

    private const GUEST_TTL = 60;

    private const MEMBER_TTL = 20;

    /** Posts asked for per page, and how many pages, when rows come from /api/posts. */
    private const PAGE = 50;

    private const PAGES = 4;

    private readonly StatelessApi $nobody;

    public function __construct(
        private readonly Client $api,
        private readonly Settings $settings,
        private readonly Cache $cache,
        private readonly ExtensionManager $extensions,
        private readonly UrlGenerator $url,
        private readonly LoggerInterface $log,
        private readonly LocaleManager $locales,
    ) {
        $this->nobody = StatelessApi::from($api);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(ServerRequestInterface $request, User $actor, ?string $tagParam, int $limit): array
    {
        $limit = Settings::clamp($limit);
        $tag = $this->resolveTag($tagParam, $actor);
        $version = (string) $this->cache->get(self::VERSION, '0');
        $id = ($tag['id'] ?? 0).'|'.$limit;

        if (! $actor->isGuest()) {
            $key = 'ernestdefoe-latest-replies.m.'.$actor->id.'.'.md5($version.'|'.$id);
            $rows = $this->cache->get($key);
            if (is_array($rows)) {
                return $rows;
            }
            $rows = $this->build(fn (string $path, array $query) => $this->api->withParentRequest($request)->withQueryParams($query)->get($path), $actor, $tag, $limit);
            if ($rows !== null) {
                $this->cache->put($key, $rows, self::MEMBER_TTL);
            }

            return $rows ?? [];
        }

        $key = 'ernestdefoe-latest-replies.g.'.md5($id);
        $fresh = function () use ($key, $version): ?array {
            $entry = $this->cache->get($key);

            return is_array($entry) && $entry['v'] === $version && time() - $entry['at'] < self::GUEST_TTL ? $entry['rows'] : null;
        };
        if (($rows = $fresh()) !== null) {
            return $rows;
        }
        // A build that just failed isn't retried on every request, and nothing older is served
        // meanwhile: the change that made it stale may have been a post hidden for privacy.
        if ($this->cache->get($key.'.failed') === $version) {
            return [];
        }

        // One rebuild at a time. While another request rebuilds, the previous answer; with none
        // yet (a cold cache), wait briefly for theirs rather than building it again.
        $entry = $this->cache->get($key);
        $lock = $this->cache->getStore() instanceof LockProvider ? $this->cache->getStore()->lock($key.'.lock', 15) : null;
        if ($lock && ! $lock->get()) {
            if (is_array($entry)) {
                return $entry['rows'];
            }
            try {
                $lock->block(3);
            } catch (LockTimeoutException) {
                return [];
            }
            if (($rows = $fresh()) !== null) {
                $lock->release();

                return $rows;
            }
        }

        try {
            $rows = $this->inForumLocale(fn () => $this->build(fn (string $path, array $query) => $this->nobody->asNobody($path, $query), null, $tag, $limit));
            if ($rows === null) {
                $this->cache->put($key.'.failed', $version, 15);

                return [];
            }
            $this->cache->put($key, ['v' => $version, 'at' => time(), 'rows' => $rows], 600);

            return $rows;
        } finally {
            $lock?->release();
        }
    }

    /**
     * The shared guest answer in the forum's own language, not the language of whichever guest
     * happened to rebuild it (gates translate their placeholders).
     *
     * @template T
     * @param callable(): T $build
     * @return T
     */
    private function inForumLocale(callable $build): mixed
    {
        $previous = $this->locales->getLocale();
        $this->locales->setLocale($this->settings->forumLocale());

        try {
            return $build();
        } finally {
            $this->locales->setLocale($previous);
        }
    }

    /** Every cached answer is stale: a new version makes every old one unusable. */
    public function forget(): void
    {
        $this->cache->forever(self::VERSION, uniqid('', true));
    }

    /**
     * ?tag= as exactly one existing tag this viewer can see, or no tag at all: anything else
     * (another case, a list, a tag they can't see, nonsense) is the forum-wide list, so made-up
     * values can't each make a cache entry or tell anyone which hidden tags exist.
     *
     * @return array{id: int, slug: string}|null
     */
    private function resolveTag(?string $param, User $actor): ?array
    {
        if ($param === null || $param === '' || str_contains($param, ',') || ! $this->tagsOn()) {
            return null;
        }

        $tag = \Flarum\Tags\Tag::query()->whereVisibleTo($actor)->where('slug', $param)->first(['id', 'slug']);

        return $tag && $tag->slug === $param ? ['id' => (int) $tag->id, 'slug' => (string) $tag->slug] : null;
    }

    /**
     * @param callable(string, array<string, mixed>): ResponseInterface $get
     * @param array{id: int, slug: string}|null $tag
     * @return list<array<string, mixed>>|null null when the API could not answer
     */
    private function build(callable $get, ?User $member, ?array $tag, int $limit): ?array
    {
        $length = $this->settings->excerptLength();
        [$include, $exclude] = $this->tagFilter($tag);
        $ignored = $member ? $this->ignoredBy($member) : [];
        // One row per discussion comes from /api/discussions, unless the member ignores someone:
        // then from posts, newest first, so a discussion whose last reply is theirs falls back
        // to the reply before it instead of disappearing.
        $fromPosts = $this->settings->perPost() || $ignored;
        $oncePerDiscussion = ! $this->settings->perPost();

        // Sparse fieldsets: only what a row needs, so the dozens of fields other extensions add to
        // discussions and posts (and their queries) aren't computed. Gates that redact the API's
        // output still run on it (Spoiler Shield looks marks up itself when its field isn't asked for).
        $fields = [
            'discussions' => 'title,slug,lastPostNumber,lastPostedAt,isHidden,hiddenAt,lastPost,lastPostedUser,tags',
            'posts' => 'number,createdAt,contentType,'.($length ? 'contentHtml,' : '').'isHidden,hiddenAt,isApproved,user,discussion',
            'users' => 'displayName,slug,avatarUrl,color',
            'tags' => 'name,slug,color,position,parent',
        ];

        if (! $fromPosts) {
            $filter = [];
            if ($include) {
                $filter['tag'] = implode(',', $include);
            }
            if ($exclude) {
                $filter['-tag'] = implode(',', $exclude);
            }
            $doc = $this->ask($get, '/discussions', [
                'filter' => $filter,
                'sort' => '-lastPostedAt',
                // Some may be dropped below (private, no replies yet): ask for a few more.
                'page' => ['limit' => min(50, $limit * 2 + 5)],
                // The last post itself only for its excerpt: the discussion already says its number and time.
                'include' => implode(',', array_filter([$length ? 'lastPost' : null, 'lastPostedUser', $this->tagsOn() ? 'tags' : null])),
                'fields' => $fields,
            ]);

            $seen = [];

            return $doc === null ? null : $this->collect($doc, false, $length, $limit, $include, $exclude, $ignored, false, $seen);
        }

        // /api/posts has no tag filter, so a quiet tag may need a few pages; bounded.
        $rows = [];
        $seen = [];
        $postIds = [];
        for ($page = 0; $page < self::PAGES; $page++) {
            $doc = $this->ask($get, '/posts', [
                'filter' => ['type' => 'comment'],
                'sort' => '-createdAt',
                'page' => ['limit' => self::PAGE, 'offset' => $page * self::PAGE],
                'include' => $this->tagsOn() ? 'user,discussion,discussion.tags' : 'user,discussion',
                'fields' => $fields,
            ]);
            if ($doc === null) {
                return $page === 0 ? null : $rows;
            }

            // A post can move to the next page while we read (new posts push the list down): once only.
            foreach ($this->collect($doc, true, $length, $limit - count($rows), $include, $exclude, $ignored, $oncePerDiscussion, $seen) as $row) {
                if (! isset($postIds[$row['id']])) {
                    $postIds[$row['id']] = true;
                    $rows[] = $row;
                }
            }
            if (count($rows) >= $limit || count($doc['data']) < self::PAGE) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @param callable(string, array<string, mixed>): ResponseInterface $get
     * @param array<string, mixed> $query
     * @return array<string, mixed>|null
     */
    private function ask(callable $get, string $path, array $query): ?array
    {
        try {
            $response = $get($path, $query);
            $doc = json_decode((string) $response->getBody(), true);
        } catch (Throwable $e) {
            $this->log->warning('[latest-replies] '.$e->getMessage());

            return null;
        }

        if ($response->getStatusCode() !== 200 || ! is_array($doc) || ! isset($doc['data']) || ! is_array($doc['data'])) {
            $this->log->warning('[latest-replies] the API answered '.$response->getStatusCode());

            return null;
        }

        return $doc;
    }

    /**
     * The rows in one API answer.
     *
     * @param array<string, mixed> $doc
     * @param string[] $include
     * @param string[] $exclude
     * @param array<int, true> $ignored
     * @param bool $once one row per discussion: skip discussions in $seen, and add each listed one
     * @param array<int, true> $seen
     * @return list<array<string, mixed>>
     */
    private function collect(array $doc, bool $posts, int $length, int $limit, array $include, array $exclude, array $ignored, bool $once, array &$seen): array
    {
        $included = [];
        foreach ($doc['included'] ?? [] as $resource) {
            $included[$resource['type'].':'.$resource['id']] = $resource;
        }
        $related = function (array $resource, string $name) use ($included): ?array {
            $ref = $resource['relationships'][$name]['data'] ?? null;

            return is_array($ref) && isset($ref['type']) ? ($included[$ref['type'].':'.$ref['id']] ?? null) : null;
        };

        $firstPosts = $this->settings->firstPosts();
        [$privateDiscussions, $privatePosts] = $this->privateIds($doc['data'], $posts);
        $rows = [];

        foreach ($doc['data'] as $resource) {
            if (count($rows) >= $limit) {
                break;
            }

            if ($posts) {
                $post = $resource;
                $discussion = $related($post, 'discussion');
                $user = $related($post, 'user');
            } else {
                $discussion = $resource;
                // Core keeps a discussion's last post on its newest visible, approved comment.
                $post = $length ? $related($discussion, 'lastPost') : ['id' => null, 'attributes' => [
                    'number' => $discussion['attributes']['lastPostNumber'] ?? null,
                    'createdAt' => $discussion['attributes']['lastPostedAt'] ?? null,
                ]];
                $user = $related($discussion, 'lastPostedUser');
            }

            if (! $post || ! $discussion || ! $this->shows($post, $discussion)
                || isset($privateDiscussions[(int) $discussion['id']]) || ($post['id'] !== null && isset($privatePosts[(int) $post['id']]))
                || ($user && isset($ignored[(int) $user['id']]))
                || ($once && isset($seen[(int) $discussion['id']]))) {
                continue;
            }

            $number = (int) ($post['attributes']['number'] ?? 0);
            if (! $firstPosts && $number <= 1) {
                continue;
            }

            $tags = $this->tagsOn() ? $this->tagsOf($discussion, $included) : [];
            if ($posts && ! $this->tagsMatch($tags, $include, $exclude)) {
                continue;
            }

            if ($once) {
                $seen[(int) $discussion['id']] = true;
            }
            $rows[] = $this->row($post, $discussion, $user, $tags, $length);
        }

        return $rows;
    }

    /**
     * 🚨 Private discussions are never listed, not even to their participants (Ernest's rule,
     * fixed, not a setting). Read from the rows themselves, so it holds whatever Byobu (or
     * anything else that makes discussions private) calls its attributes. One query.
     *
     * @param list<array<string, mixed>> $data
     * @return array{0: array<int, true>, 1: array<int, true>}
     */
    private function privateIds(array $data, bool $posts): array
    {
        $discussionIds = $postIds = [];
        foreach ($data as $resource) {
            if ($posts) {
                $postIds[] = (int) $resource['id'];
                $discussionIds[] = (int) ($resource['relationships']['discussion']['data']['id'] ?? 0);
            } else {
                $discussionIds[] = (int) $resource['id'];
            }
        }

        $discussions = $discussionIds ? Discussion::query()->whereIn('id', array_unique($discussionIds))->where('is_private', true)->pluck('id')->all() : [];
        $postRows = $postIds ? Post::query()->whereIn('id', $postIds)->where('is_private', true)->pluck('id')->all() : [];

        return [array_fill_keys(array_map('intval', $discussions), true), array_fill_keys(array_map('intval', $postRows), true)];
    }

    /**
     * Members this viewer ignores (fof/ignore-users): their replies are left out. One query,
     * and nothing at all without that extension.
     *
     * @return array<int, true>
     */
    private function ignoredBy(User $member): array
    {
        if (! $this->extensions->isEnabled('fof-ignore-users')) {
            return [];
        }

        $ids = $member->getConnection()->table('ignored_user')->where('user_id', $member->id)->pluck('ignored_user_id')->all();

        return array_fill_keys(array_map('intval', $ids), true);
    }

    /** Our own rules, on top of everything the API already left out. */
    private function shows(array $post, array $discussion): bool
    {
        $p = $post['attributes'] ?? [];
        $d = $discussion['attributes'] ?? [];

        return ($p['contentType'] ?? 'comment') === 'comment'
            // A moderator's API answer still carries hidden and unapproved posts and hidden discussions.
            && empty($p['isHidden']) && empty($p['hiddenAt'])
            && ($p['isApproved'] ?? true) !== false
            && empty($d['isHidden']) && empty($d['hiddenAt']);
    }

    /**
     * @param list<array<string, mixed>> $tags
     * @return array<string, mixed>
     */
    private function row(array $post, array $discussion, ?array $user, array $tags, int $length): array
    {
        $p = $post['attributes'];
        $d = $discussion['attributes'];
        $id = (int) $discussion['id'];
        // The API's slug is the route's whole id part ("12-title", or "12" for a title that slugs to nothing).
        $slug = (string) ($d['slug'] ?? '');
        if (! str_starts_with($slug.'-', $id.'-')) {
            $slug = $id.($slug !== '' ? '-'.$slug : '');
        }
        $number = (int) $p['number'];

        $primary = null;
        foreach ($tags as $tag) {
            if ($primary === null || ($tag['position'] !== null && $primary['position'] === null)) {
                $primary = $tag;
            }
        }

        return [
            'id' => $post['id'] !== null ? (int) $post['id'] : null,
            'number' => $number,
            'createdAt' => $p['createdAt'] ?? null,
            'discussionId' => $id,
            'discussionTitle' => (string) ($d['title'] ?? ''),
            'slug' => $slug,
            'url' => $this->url->to('forum')->route('discussion', ['id' => $slug, 'near' => $number]),
            'user' => $user ? [
                'displayName' => (string) ($user['attributes']['displayName'] ?? ''),
                'slug' => (string) ($user['attributes']['slug'] ?? $user['id']),
                'avatarUrl' => $user['attributes']['avatarUrl'] ?? null,
                'color' => $user['attributes']['color'] ?? null,
            ] : null,
            'tag' => $primary ? ['name' => $primary['name'], 'color' => $primary['color'], 'slug' => $primary['slug']] : null,
            'excerpt' => $length ? Excerpt::make($p['contentHtml'] ?? null, $length) : null,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $included
     * @return list<array{id: int, name: string, slug: string, color: ?string, position: ?int, parentId: ?int}>
     */
    private function tagsOf(array $discussion, array $included): array
    {
        $tags = [];
        foreach ($discussion['relationships']['tags']['data'] ?? [] as $ref) {
            $tag = $included['tags:'.$ref['id']] ?? null;
            if (! $tag) {
                continue;
            }
            $a = $tag['attributes'];
            $tags[] = [
                'id' => (int) $tag['id'],
                'name' => (string) ($a['name'] ?? ''),
                'slug' => (string) ($a['slug'] ?? ''),
                'color' => $a['color'] ?? null,
                'position' => isset($a['position']) ? (int) $a['position'] : null,
                'parentId' => isset($tag['relationships']['parent']['data']['id']) ? (int) $tag['relationships']['parent']['data']['id'] : null,
            ];
        }

        return $tags;
    }

    /**
     * Rows from /api/posts filter here: it has no tag filter.
     *
     * @param list<array{slug: string}> $tags
     * @param string[] $include
     * @param string[] $exclude
     */
    private function tagsMatch(array $tags, array $include, array $exclude): bool
    {
        $slugs = array_column($tags, 'slug');

        return (! $include || array_intersect($include, $slugs)) && ! array_intersect($exclude, $slugs);
    }

    /**
     * Slugs to include and exclude. A tag page shows that tag (a discussion in a
     * child tag always carries its parent too); otherwise the admin's choice.
     *
     * @param array{id: int, slug: string}|null $tag
     * @return array{0: string[], 1: string[]}
     */
    private function tagFilter(?array $tag): array
    {
        if (! $this->tagsOn()) {
            return [[], []];
        }

        $exclude = $this->slugs($this->settings->tagIds('exclude_tags'));
        $include = $tag !== null ? [$tag['slug']] : $this->slugs($this->settings->tagIds('include_tags'));

        return [$include, array_values(array_diff($exclude, $include))];
    }

    /**
     * @param int[] $ids
     * @return string[]
     */
    private function slugs(array $ids): array
    {
        return $ids ? \Flarum\Tags\Tag::query()->whereIn('id', $ids)->pluck('slug')->all() : [];
    }

    private function tagsOn(): bool
    {
        return class_exists(\Flarum\Tags\Tag::class) && $this->extensions->isEnabled('flarum-tags');
    }
}
