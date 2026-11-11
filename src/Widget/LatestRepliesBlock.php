<?php

namespace ErnestDefoe\LatestReplies\Widget;

use Ernestdefoe\PageBuilder\Block\BlockInterface;
use Flarum\User\User;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Latest Replies as a Page Builder block.
 *
 * resolve() hands Page Builder nothing: the client fetches /api/latest-replies
 * like every other host, so the rows have one source and one set of rules.
 * Registered from extend.php only when Page Builder is installed.
 */
class LatestRepliesBlock implements BlockInterface
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    private function t(string $key): string
    {
        return $this->translator->trans('ernestdefoe-latest-replies.lib.'.$key);
    }

    public function type(): string
    {
        return 'latest-replies';
    }

    public function name(): string
    {
        return $this->t('title');
    }

    public function icon(): string
    {
        return 'fas fa-comments';
    }

    public function category(): string
    {
        return 'forum';
    }

    public function defaultSettings(): array
    {
        return ['title' => '', 'count' => 5, 'wide' => false];
    }

    public function settingsSchema(): array
    {
        return [
            ['key' => 'title', 'type' => 'text', 'label' => $this->t('widget_title'), 'default' => ''],
            ['key' => 'count', 'type' => 'number', 'label' => $this->t('widget_count'), 'default' => 5],
            ['key' => 'wide', 'type' => 'toggle', 'label' => $this->t('widget_wide'), 'default' => false],
        ];
    }

    public function resolve(array $settings, User $actor): array
    {
        return [];
    }
}
