<?php

namespace ErnestDefoe\LatestReplies\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\LatestReplies\Excerpt;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class LatestRepliesTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'ernestdefoe-latest-replies');

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
                $post(12, 1, 2, 3, 30, 'PUBLIC-REPLY'),
                $post(13, 1, 3, 2, 25, 'HIDDEN-REPLY', ['hidden_at' => $at(24), 'hidden_user_id' => 1]),
                $post(21, 2, 1, 3, 100, 'Staff start'),
                $post(22, 2, 2, 3, 20, 'STAFF-REPLY'),
                $post(31, 3, 1, 2, 100, 'Private start', ['is_private' => 1]),
                $post(32, 3, 2, 3, 10, 'PRIVATE-REPLY', ['is_private' => 1]),
                $post(41, 4, 1, 2, 5, 'New discussion body'),
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
    public function new_discussions_can_be_left_out(): void
    {
        $this->setting('ernestdefoe-latest-replies.first_posts', '0');
        $this->assertSame(['Public talk'], $this->titles(null));
    }

    #[Test]
    public function a_tag_page_lists_only_that_tag(): void
    {
        $this->assertSame(['Staff only'], $this->titles(3, ['tag' => 'staff']));
        $this->assertSame([], $this->titles(2, ['tag' => 'staff']));
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

        // User 3 wrote Public talk's newest reply; Brand new is user 2's own.
        $this->assertSame(['Brand new'], $this->titles(2));
        $this->assertSame(['Brand new', 'Public talk'], $this->titles(null));
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
    }
}
