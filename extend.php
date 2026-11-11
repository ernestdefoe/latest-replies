<?php

/*
 * Latest Replies: a widget of the newest replies on the forum.
 *
 * The list is never read from the database by us: Feed asks Flarum's own API
 * (as the viewer, through the full middleware pipe), so every visibility rule
 * and every redaction other extensions apply (Tags, Approval, Byobu, Spoiler
 * Shield, Velvet Rope…) has already happened. Feed only keeps what a row needs,
 * drops private discussions, and builds excerpts by allow-list. Guests share one
 * cached answer; members are asked live, since what they may see is their own.
 *
 * forum.js mounts the widget (stock sidebar, top of the list, discussion page)
 * and offers it to Bespoke, FoF Widgets and Page Builder. Realtime only says
 * "something changed"; the widget then asks again.
 */

use ErnestDefoe\LatestReplies\Api\ListController;
use ErnestDefoe\LatestReplies\Feed;
use Flarum\Discussion\Event as DiscussionEvent;
use Flarum\Extend;
use Flarum\Post\Event as PostEvent;
use Flarum\Settings\Event\Saved;
use Flarum\User\Event as UserEvent;

$forget = fn () => resolve(Feed::class)->forget();

$extenders = [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Routes('api'))
        ->get('/latest-replies', 'latestReplies.index', ListController::class),

    // 🚨 Literal keys: anything here runs while the forum boots, and a class constant would make
    // every request load our classes then (a worker with a stale autoload map fatals on all of them).
    (new Extend\Settings())
        ->default('ernestdefoe-latest-replies.count', 5)
        ->default('ernestdefoe-latest-replies.mode', 'discussion')
        ->default('ernestdefoe-latest-replies.placement', 'sidebar')
        ->default('ernestdefoe-latest-replies.discussion_page', false)
        ->default('ernestdefoe-latest-replies.first_posts', true)
        ->default('ernestdefoe-latest-replies.excerpt', false)
        ->default('ernestdefoe-latest-replies.excerpt_length', 100)
        ->default('ernestdefoe-latest-replies.include_tags', '')
        ->default('ernestdefoe-latest-replies.exclude_tags', '')
        ->serializeToForum('latestRepliesCount', 'ernestdefoe-latest-replies.count', 'intval')
        ->serializeToForum('latestRepliesPlacement', 'ernestdefoe-latest-replies.placement')
        ->serializeToForum('latestRepliesDiscussionPage', 'ernestdefoe-latest-replies.discussion_page', 'boolval'),

    // Anything that can change a guest's list forgets the shared answer.
    (new Extend\Event())
        ->listen(PostEvent\Posted::class, $forget)
        ->listen(PostEvent\Revised::class, $forget)
        ->listen(PostEvent\Hidden::class, $forget)
        ->listen(PostEvent\Restored::class, $forget)
        ->listen(PostEvent\Deleted::class, $forget)
        ->listen(DiscussionEvent\Renamed::class, $forget)
        ->listen(DiscussionEvent\Hidden::class, $forget)
        ->listen(DiscussionEvent\Restored::class, $forget)
        ->listen(DiscussionEvent\Deleted::class, $forget)
        // After any discussion is written: Spoiler Shield marks, Byobu recipients, tags and anything
        // else other extensions keep on the row (after the write, so a rebuild can't read the old row).
        ->listen('eloquent.saved: Flarum\\Discussion\\Discussion', $forget)
        // Any setting: ours, and other extensions' that change what guests may read (Spoiler Shield, Velvet Rope…).
        ->listen(Saved::class, $forget)
        // Names and pictures are in the rows.
        ->listen(UserEvent\Deleted::class, $forget)
        ->listen(UserEvent\Renamed::class, $forget)
        ->listen(UserEvent\AvatarChanged::class, $forget),
];

// Approval and tag changes move posts in and out of a guest's view too.
if (class_exists(\Flarum\Approval\Event\PostWasApproved::class)) {
    $extenders[] = (new Extend\Event())->listen(\Flarum\Approval\Event\PostWasApproved::class, $forget);
}
if (class_exists(\Flarum\Tags\Event\DiscussionWasTagged::class)) {
    $extenders[] = (new Extend\Event())
        ->listen(\Flarum\Tags\Event\DiscussionWasTagged::class, $forget)
        // A tag renamed, recoloured, restricted or deleted.
        ->listen('eloquent.saved: Flarum\\Tags\\Tag', $forget)
        ->listen('eloquent.deleted: Flarum\\Tags\\Tag', $forget);
}
// Byobu: a discussion going private or public.
if (class_exists(\FoF\Byobu\Events\RecipientsChanged::class)) {
    $extenders[] = (new Extend\Event())
        ->listen(\FoF\Byobu\Events\RecipientsChanged::class, $forget)
        ->listen(\FoF\Byobu\Events\Created::class, $forget)
        ->listen(\FoF\Byobu\Events\DiscussionMadePublic::class, $forget);
}

// The Page Builder block only offers the widget in its editor; the client fetches like every other host.
if (class_exists(\Ernestdefoe\PageBuilder\Extend\PageBuilderBlock::class)) {
    $extenders[] = new \Ernestdefoe\PageBuilder\Extend\PageBuilderBlock(\ErnestDefoe\LatestReplies\Widget\LatestRepliesBlock::class);
}

return $extenders;
