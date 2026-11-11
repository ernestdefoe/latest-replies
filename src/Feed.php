<?php

namespace ErnestDefoe\LatestReplies;

use Flarum\Api\Client;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The rows of the widget, for one viewer.
 *
 * 🚨 Never a query of our own: the rows come from Flarum's API, asked as this
 * viewer through the whole API pipe, so whatever any extension hides or redacts
 * there (visibility scopes, Byobu, Approval, Spoiler Shield, Velvet Rope…) is
 * already gone. Our own rules only take more away: private discussions, hidden
 * and unapproved posts (a moderator sees those in the API), replies by members
 * the viewer ignores (fof/ignore-users), excerpts by allow-list. The answer is plain JSON shaped so that gates which scrub other
 * extensions' JSON (discussionId + discussionTitle, a /d/{id} url) find it.
 *
 * Guests share one answer per tag and size for a minute, forgotten whenever a
 * post or discussion changes. Members are asked live: what a member may see is
 * their own, so a cache shared by group could hand one member another's rows.
 */
class Feed
{
    private const VERSION = 'ernestdefoe-latest-replies.v';

    public function __construct(
        private readonly Client $api,
        private readonly Settings $settings,
        private readonly Cache $cache,
        private readonly ExtensionManager $extensions,
        private readonly UrlGenerator $url,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(ServerRequestInterface $request, User $actor, ?string $tag, int $limit): array
    {
        $limit = Settings::clamp($limit);
        $tag = $tag !== null && $tag !== '' && $this->tagsOn() ? $tag : null;

        if (! $actor->isGuest()) {
            return $this->build($request, $tag, $limit) ?? [];
        }

        $key = 'ernestdefoe-latest-replies.guest.'.md5($this->cache->get(self::VERSION, '0').'|'.$tag.'|'.$limit);

        $rows = $this->cache->get($key);
        if (is_array($rows)) {
            return $rows;
        }

        $rows = $this->build($request, $tag, $limit);
        // An empty answer for a tag isn't kept: made-up ?tag= values would otherwise each add a cache entry.
        if ($rows === null || ($rows === [] && $tag !== null)) {
            return $rows ?? [];
        }

        $this->cache->put($key, $rows, 60);

        return $rows;
    }

    /** Every guest answer is stale: a new version makes every old key unreachable. */
    public function forget(): void
    {
        $this->cache->forever(self::VERSION, uniqid('', true));
    }

    /**
     * @return list<array<string, mixed>>|null null when the API could not answer
     */
    private function build(ServerRequestInterface $request, ?string $tag, int $limit): ?array
    {
        $perPost = $this->settings->perPost();
        $length = $this->settings->excerptLength();
        [$include, $exclude] = $this->tagFilter($tag);

        if ($perPost) {
            $path = '/posts';
            $params = [
                'filter' => ['type' => 'comment'],
                'sort' => '-createdAt',
                // /api/posts has no tag filter, so ask for more when tags narrow the list.
                'page' => ['limit' => $include || $exclude ? 50 : min(50, $limit * 2 + 5)],
                'include' => $this->tagsOn() ? 'user,discussion,discussion.tags' : 'user,discussion',
            ];
        } else {
            $filter = [];
            if ($include) {
                $filter['tag'] = implode(',', $include);
            }
            if ($exclude) {
                $filter['-tag'] = implode(',', $exclude);
            }
            $path = '/discussions';
            $params = [
                'filter' => $filter,
                'sort' => '-lastPostedAt',
                // Some may be dropped below (private, no replies yet): ask for a few more.
                'page' => ['limit' => min(50, $limit * 2 + 5)],
                // The last post itself only for its excerpt: every extension adds to a post's
                // payload (mentions, likes…), and the discussion already says its number and time.
                'include' => implode(',', array_filter([$length ? 'lastPost' : null, 'lastPostedUser', $this->tagsOn() ? 'tags' : null])),
            ];
        }

        // Sparse fieldsets: only what a row needs, so the dozens of fields other extensions add to
        // discussions and posts (and their queries) aren't computed. Gates that redact the API's
        // output still run on it (Spoiler Shield looks marks up itself when its field isn't asked for).
        $params['fields'] = [
            'discussions' => 'title,slug,lastPostNumber,lastPostedAt,isHidden,hiddenAt,lastPost,lastPostedUser,tags',
            'posts' => 'number,createdAt,contentType,contentHtml,isHidden,hiddenAt,isApproved,user,discussion',
            'users' => 'displayName,slug,avatarUrl,color',
            'tags' => 'name,slug,color,position,parent',
        ];

        try {
            $response = $this->api->withParentRequest($request)->withQueryParams($params)->get($path);
            $doc = json_decode((string) $response->getBody(), true);
        } catch (Throwable $e) {
            $this->log->warning('[latest-replies] '.$e->getMessage());

            return null;
        }

        if ($response->getStatusCode() !== 200 || ! is_array($doc) || ! isset($doc['data']) || ! is_array($doc['data'])) {
            $this->log->warning('[latest-replies] the API answered '.$response->getStatusCode());

            return null;
        }

        $included = [];
        foreach ($doc['included'] ?? [] as $resource) {
            $included[$resource['type'].':'.$resource['id']] = $resource;
        }
        $related = function (array $resource, string $name) use ($included): ?array {
            $ref = $resource['relationships'][$name]['data'] ?? null;

            return is_array($ref) && isset($ref['type']) ? ($included[$ref['type'].':'.$ref['id']] ?? null) : null;
        };

        $firstPosts = $this->settings->firstPosts();
        [$privateDiscussions, $privatePosts] = $this->privateIds($doc['data'], $perPost);
        $ignored = $this->ignoredBy($request);
        $rows = [];

        foreach ($doc['data'] as $resource) {
            if ($perPost) {
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
                || ($user && isset($ignored[(int) $user['id']]))) {
                continue;
            }

            $number = (int) ($post['attributes']['number'] ?? 0);
            if (! $firstPosts && $number <= 1) {
                continue;
            }

            $tags = $this->tagsOn() ? $this->tagsOf($discussion, $included) : [];
            if ($perPost && ! $this->tagsMatch($tags, $include, $exclude)) {
                continue;
            }

            $rows[] = $this->row($post, $discussion, $user, $tags, $length);

            if (count($rows) >= $limit) {
                break;
            }
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
    private function privateIds(array $data, bool $perPost): array
    {
        $discussionIds = $postIds = [];
        foreach ($data as $resource) {
            if ($perPost) {
                $postIds[] = (int) $resource['id'];
                $discussionIds[] = (int) ($resource['relationships']['discussion']['data']['id'] ?? 0);
            } else {
                $discussionIds[] = (int) $resource['id'];
            }
        }

        $discussions = $discussionIds ? Discussion::query()->whereIn('id', array_unique($discussionIds))->where('is_private', true)->pluck('id')->all() : [];
        $posts = $postIds ? Post::query()->whereIn('id', $postIds)->where('is_private', true)->pluck('id')->all() : [];

        return [array_fill_keys(array_map('intval', $discussions), true), array_fill_keys(array_map('intval', $posts), true)];
    }

    /**
     * Members this viewer ignores (fof/ignore-users): their replies are left out. One query,
     * members only, and nothing at all without that extension.
     *
     * @return array<int, true>
     */
    private function ignoredBy(ServerRequestInterface $request): array
    {
        $actor = RequestUtil::getActor($request);
        if ($actor->isGuest() || ! $this->extensions->isEnabled('fof-ignore-users')) {
            return [];
        }

        $ids = $actor->getConnection()->table('ignored_user')->where('user_id', $actor->id)->pluck('ignored_user_id')->all();

        return array_fill_keys(array_map('intval', $ids), true);
    }

    /** Our own rules, on top of everything the API already left out. */
    private function shows(array $post, array $discussion): bool
    {
        $p = $post['attributes'] ?? [];
        $d = $discussion['attributes'] ?? [];

        return ($p['contentType'] ?? 'comment') === 'comment'
            // A moderator's API answer still carries hidden and unapproved posts.
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
     * Per-post mode filters here: /api/posts has no tag filter.
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
     * @return array{0: string[], 1: string[]}
     */
    private function tagFilter(?string $tag): array
    {
        if (! $this->tagsOn()) {
            return [[], []];
        }

        $exclude = $this->slugs($this->settings->tagIds('exclude_tags'));
        $include = $tag !== null ? [$tag] : $this->slugs($this->settings->tagIds('include_tags'));

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
