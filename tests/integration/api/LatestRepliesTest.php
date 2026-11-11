<?php

namespace ErnestDefoe\LatestReplies\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\LatestReplies\Excerpt;
use ErnestDefoe\LatestReplies\Tests\integration\Fixtures\FailingApi;
use ErnestDefoe\LatestReplies\Tests\integration\Fixtures\RevealToCrawlers;
use Flarum\Discussion\Discussion;
use Flarum\Extend;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\Test;

class LatestRepliesTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'flarum-flags', 'flarum-approval', 'ernestdefoe-latest-replies');

        $at = fn (int $minutes) => Carbon::now()->subMinutes($minutes);
        $post = fn (int $id, int $discussion, int $number, int $user, int $ago, string $text, array $extra = []) => $extra + [
            'id' => $id, 'discussion_id' => $discussion, 'number' => $number, 'user_id' => $user, 'type' => 'comment',
            'created_at' => $at($ago), 'content' => '<t><p>'.$text.'</p></t>', 'is_private' => 0,
        ];
        $discussion = fn (int $id, string $title, int $lastPost, int $lastNumber, int $ago, array $extra = []) => $extra + [
            'id' => $id, 'title' => $title, 'slug' => strtolower(str_replace(' ', '-', $title)), 'user_id' => 2, 'created_at' => $at(100),
            'first_post_id' => $id * 10 + 1, 'comment_count' => $lastNumber, 'last_post_id' => $lastPost, 'last_post_number' => $lastNumber,
            'last_posted_at' => $at($ago), 'last_posted_user_id' => 3, 'is_private' => 0,
        ];

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'mod', 'email' => 'mod@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_user' => [['user_id' => 3, 'group_id' => Group::MODERATOR_ID]],
            'tags' => [
                ['id' => 1, 'name' => 'General', 'slug' => 'general', 'position' => 0, 'is_restricted' => 0],
                ['id' => 2, 'name' => 'Staff', 'slug' => 'staff', 'position' => 1, 'is_restricted' => 1],
            ],
            'group_permission' => [['group_id' => Group::MODERATOR_ID, 'permission' => 'tag2.viewForum']],
            Discussion::class => [
                $discussion(1, 'Public talk', 12, 2, 30),
                $discussion(2, 'Staff only', 22, 2, 20),
                $discussion(3, 'Private chat', 32, 2, 10, ['is_private' => 1]),
                $discussion(4, 'Brand new', 41, 1, 5, ['last_posted_user_id' => 2]),
            ],
            'discussion_tag' => [
                ['discussion_id' => 1, 'tag_id' => 1], ['discussion_id' => 2, 'tag_id' => 2],
                ['discussion_id' => 3, 'tag_id' => 1], ['discussion_id' => 4, 'tag_id' => 1],
            ],
            Post::class => [
                $post(11, 1, 1, 2, 100, 'Start'),
                $post(12, 1, 2, 3, 30, 'PUBLIC-REPLY CLASSIFIED'),
                $post(13, 1, 3, 2, 25, 'HIDDEN-REPLY', ['hidden_at' => $at(24), 'hidden_user_id' => 1]),
                $post(21, 2, 1, 3, 100, 'Staff start'),
                $post(22, 2, 2, 3, 20, 'STAFF-REPLY'),
                $post(31, 3, 1, 2, 100, 'Private start', ['is_private' => 1]),
                $post(32, 3, 2, 3, 10, 'PRIVATE-REPLY', ['is_private' => 1]),
                $post(41, 4, 1, 2, 5, 'New discussion body'),
                $post(42, 4, 2, 3, 4, 'AWAITING-APPROVAL', ['is_approved' => 0]),
            ],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function rows(?int $as, array $query = []): array
    {
        $response = $this->send($this->request('GET', '/api/latest-replies', $as ? ['authenticatedAs' => $as] : [])->withQueryParams($query));
        $this->assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true)['rows'];
    }

    /** @return string[] */
    private function titles(?int $as, array $query = []): array
    {
        return array_column($this->rows($as, $query), 'discussionTitle');
    }

    #[Test]
    public function guests_see_public_discussions_newest_first(): void
    {
        $this->assertSame(['Brand new', 'Public talk'], $this->titles(null));
    }

    #[Test]
    public function a_restricted_tag_shows_only_to_those_who_may_see_it(): void
    {
        $this->assertNotContains('Staff only', $this->titles(2));
        $this->assertContains('Staff only', $this->titles(3));
    }

    #[Test]
    public function private_discussions_are_never_listed_even_for_admins(): void
    {
        $this->assertNotContains('Private chat', $this->titles(1));
    }

    #[Test]
    public function a_byobu_private_discussion_is_never_listed_even_to_its_participants(): void
    {
        $this->extension('flarum-tags', 'flarum-flags', 'flarum-approval', 'fof-byobu', 'ernestdefoe-latest-replies');
        $this->prepareDatabase(['recipients' => [
            ['discussion_id' => 3, 'user_id' => 2, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
            ['discussion_id' => 3, 'user_id' => 3, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
        ]]);

        // The participant can open it through core's own API…
        $this->assertSame(200, $this->send($this->request('GET', '/api/discussions/3', ['authenticatedAs' => 2]))->getStatusCode());
        // …but the widget never lists it, in either mode.
        $this->assertNotContains('Private chat', $this->titles(2, ['limit' => 20]));
        $this->setting('ernestdefoe-latest-replies.mode', 'post');
        $this->assertStringNotContainsString('Private chat', json_encode($this->rows(3, ['limit' => 20])));
    }

    #[Test]
    public function new_discussions_can_be_left_out(): void
    {
        $this->setting('ernestdefoe-latest-replies.first_posts', '0');
        $this->assertSame(['Public talk'], $this->titles(null));
    }

    #[Test]
    public function a_tag_page_lists_only_that_tag(): void
    {
        $this->assertSame(['Staff only'], $this->titles(3, ['tag' => 'staff']));
        // A tag this viewer can't see is no tag: the forum-wide list, which says nothing about it.
        $this->assertSame(['Brand new', 'Public talk'], $this->titles(2, ['tag' => 'staff']));
    }

    #[Test]
    public function excluded_tags_are_left_out(): void
    {
        $this->setting('ernestdefoe-latest-replies.exclude_tags', '2');
        $this->assertNotContains('Staff only', $this->titles(3));
    }

    #[Test]
    public function per_post_mode_never_shows_hidden_posts_even_to_moderators(): void
    {
        $this->setting('ernestdefoe-latest-replies.mode', 'post');
        $this->setting('ernestdefoe-latest-replies.excerpt', '1');

        $all = json_encode($this->rows(1, ['limit' => 20]));
        $this->assertStringNotContainsString('HIDDEN-REPLY', $all);
        $this->assertStringNotContainsString('PRIVATE-REPLY', $all);
        $this->assertStringContainsString('STAFF-REPLY', $all);

        $guest = json_encode($this->rows(null, ['limit' => 20]));
        $this->assertStringContainsString('PUBLIC-REPLY', $guest);
        $this->assertStringNotContainsString('STAFF-REPLY', $guest);
    }

    #[Test]
    public function replies_by_members_the_viewer_ignores_are_left_out(): void
    {
        $this->extension('flarum-tags', 'fof-ignore-users', 'ernestdefoe-latest-replies');
        $this->prepareDatabase(['ignored_user' => [['user_id' => 2, 'ignored_user_id' => 3, 'ignored_at' => Carbon::now()]]]);

        // User 3 wrote Public talk's newest visible reply: the list falls back to the post before it.
        $rows = $this->rows(2);
        $this->assertSame(['Brand new', 'Public talk'], array_column($rows, 'discussionTitle'));
        $this->assertSame(1, $rows[1]['number']);
        $this->assertSame(2, $this->rows(null)[1]['number']);
    }

    #[Test]
    public function excerpts_are_off_by_default(): void
    {
        $this->assertNull($this->rows(null)[0]['excerpt']);
    }

    #[Test]
    public function a_new_reply_reaches_the_cached_guest_list(): void
    {
        $this->assertSame('Brand new', $this->titles(null)[0]);

        $response = $this->send($this->request('POST', '/api/posts', [
            'authenticatedAs' => 2,
            'json' => ['data' => ['type' => 'posts', 'attributes' => ['content' => 'A fresh reply'], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]]]],
        ]));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $this->assertSame('Public talk', $this->titles(null)[0]);
    }

    #[Test]
    public function the_shared_guest_answer_is_never_a_trusted_visitors_view(): void
    {
        $this->extend((new Extend\Middleware('api'))->add(RevealToCrawlers::class));
        $this->setting('ernestdefoe-latest-replies.excerpt', '1');

        // A crawler (by User-Agent, also in PHP's globals, and by IP) asks first, filling the cache.
        $previous = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $_SERVER['HTTP_USER_AGENT'] = 'Googlebot';
        try {
            $request = $this->request('GET', '/api/latest-replies');
            $crawler = new ServerRequest(['REMOTE_ADDR' => '6.6.6.6', 'HTTP_USER_AGENT' => 'Googlebot'], [], $request->getUri(), 'GET', 'php://temp', ['User-Agent' => 'Googlebot']);
            $body = (string) $this->send($crawler)->getBody();
        } finally {
            $previous === null ? (function () { unset($_SERVER['HTTP_USER_AGENT']); })() : $_SERVER['HTTP_USER_AGENT'] = $previous;
        }

        // The crawler's own view of the widget is the plain guest view: built as nobody.
        $this->assertStringContainsString('REDACTED', $body);
        $this->assertStringNotContainsString('CLASSIFIED', $body);
        $this->assertStringNotContainsString('CLASSIFIED', json_encode($this->rows(null)));
    }

    #[Test]
    public function a_members_answer_never_reaches_guests(): void
    {
        $this->assertContains('Staff only', $this->titles(3));
        $this->assertNotContains('Staff only', $this->titles(null));
    }

    #[Test]
    public function one_members_answer_never_reaches_another_member(): void
    {
        // The moderator's list (with the staff tag) is cached for them; the member asks right after.
        $this->assertContains('Staff only', $this->titles(3));
        $this->assertNotContains('Staff only', $this->titles(2));
        $this->assertContains('Staff only', $this->titles(3));
    }

    #[Test]
    public function a_failed_guest_rebuild_serves_nothing_rather_than_an_old_answer(): void
    {
        $this->extend((new Extend\Middleware('api'))->add(FailingApi::class));
        $this->assertContains('Public talk', $this->titles(null));
        // A change, then the API fails: guests get nothing, not the list from before the change.
        $this->patch(1, '/api/discussions/1', ['type' => 'discussions', 'id' => '1', 'attributes' => ['isHidden' => true]]);
        FailingApi::$failing = true;
        try {
            $this->assertSame([], $this->rows(null));
            $this->assertSame([], $this->rows(null));
        } finally {
            FailingApi::$failing = false;
        }
    }

    #[Test]
    public function hiding_or_deleting_a_discussion_reaches_the_cached_guest_list(): void
    {
        $this->assertContains('Public talk', $this->titles(null));
        $this->patch(1, '/api/discussions/1', ['type' => 'discussions', 'id' => '1', 'attributes' => ['isHidden' => true]]);
        $this->assertNotContains('Public talk', $this->titles(null));

        $this->assertContains('Brand new', $this->titles(null));
        $response = $this->send($this->request('DELETE', '/api/discussions/4', ['authenticatedAs' => 1]));
        $this->assertSame(204, $response->getStatusCode());
        $this->assertNotContains('Brand new', $this->titles(null));
    }

    #[Test]
    public function approving_a_reply_reaches_the_cached_guest_list(): void
    {
        $this->assertSame(1, $this->rows(null)[0]['number']);
        $this->patch(1, '/api/posts/42', ['type' => 'posts', 'id' => '42', 'attributes' => ['isApproved' => true]]);
        $this->assertSame(['Brand new', 2], [$this->rows(null)[0]['discussionTitle'], $this->rows(null)[0]['number']]);
    }

    #[Test]
    public function a_moderator_never_sees_hidden_discussions_or_unapproved_replies_listed(): void
    {
        $this->patch(1, '/api/discussions/1', ['type' => 'discussions', 'id' => '1', 'attributes' => ['isHidden' => true]]);
        $rows = $this->rows(1, ['limit' => 20]);
        $this->assertNotContains('Public talk', array_column($rows, 'discussionTitle'));
        // Brand new's unapproved reply (#2) is not its newest listed post.
        $this->assertSame(1, $rows[0]['number']);
    }

    #[Test]
    public function made_up_tags_are_the_forum_wide_list(): void
    {
        foreach (['STAFF', 'general,staff', 'nope', ' ', str_repeat('x', 500)] as $tag) {
            $this->assertSame(['Brand new', 'Public talk'], $this->titles(null, ['tag' => $tag]), "?tag=$tag");
        }
        $this->assertSame(['Brand new', 'Public talk'], $this->titles(null, ['tag' => 'general']));
    }

    private function patch(int $as, string $path, array $data): void
    {
        $response = $this->send($this->request('PATCH', $path, ['authenticatedAs' => $as, 'json' => ['data' => $data]]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    #[Test]
    public function excerpts_keep_only_the_replys_own_words(): void
    {
        $html = '<p>Loved it. <span class="spoiler">SPOILER</span> <code>CODE</code></p>'
            .'<blockquote><p>QUOTE</p></blockquote><pre><code>BLOCK</code></pre>'
            .'<details><summary>S</summary>DETAILS</details><div class="Embed">EMBED</div>'
            .'<p><strong>Bold</strong> and <a href="/x">a link</a> <img class="emoji" alt="😀" src="x.png"></p>'
            .'<script>alert(1)</script><unknown-tag>UNKNOWN</unknown-tag>';

        $this->assertSame('Loved it. Bold and a link 😀', Excerpt::make($html, 200));
        $this->assertSame('Loved it. Bold…', Excerpt::make($html, 16));
        $this->assertNull(Excerpt::make('<blockquote><p>only a quote</p></blockquote>', 100));
        // Allowed elements that are hidden or marked as spoilers are dropped too.
        $this->assertSame('Shown', Excerpt::make('<p>Shown</p><p hidden>H1</p><p aria-hidden="true">H2</p><p style="display: none">H3</p>'
            .'<p><strong class="SpoilerText">H4</strong></p><p style="visibility:hidden">H5</p>', 100));
    }
}
