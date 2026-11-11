<?php

namespace ErnestDefoe\LatestReplies;

use Flarum\Settings\SettingsRepositoryInterface;

final class Settings
{
    public const PREFIX = 'ernestdefoe-latest-replies.';

    public const MIN = 3;

    public const MAX = 20;

    public function __construct(private readonly SettingsRepositoryInterface $settings)
    {
    }

    public function get(string $key): mixed
    {
        return $this->settings->get(self::PREFIX.$key);
    }

    public function count(): int
    {
        return self::clamp((int) $this->get('count'));
    }

    public static function clamp(int $n): int
    {
        return max(self::MIN, min(self::MAX, $n ?: 5));
    }

    /** Every row is one discussion (its newest reply), or one post. */
    public function perPost(): bool
    {
        return $this->get('mode') === 'post';
    }

    public function firstPosts(): bool
    {
        return (bool) $this->get('first_posts');
    }

    /** Characters of excerpt, or 0 for none. */
    public function excerptLength(): int
    {
        return $this->get('excerpt') ? max(40, min(300, (int) $this->get('excerpt_length') ?: 100)) : 0;
    }

    /** The forum's default language. */
    public function forumLocale(): string
    {
        return (string) ($this->settings->get('default_locale') ?: 'en');
    }

    /** @return int[] */
    public function tagIds(string $key): array
    {
        return array_values(array_filter(array_map('intval', explode(',', (string) $this->get($key)))));
    }
}
