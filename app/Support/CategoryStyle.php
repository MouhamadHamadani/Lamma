<?php

namespace App\Support;

/**
 * Line icon and soft tint for a category tile, chosen from its slug. The `icon` column holds emoji
 * (seeded that way), which the Lamma design forbids, so it is ignored here.
 */
final class CategoryStyle
{
    private const ICONS = [
        'geography' => 'globe', 'science' => 'flask', 'sports' => 'ball', 'history' => 'landmark',
        'movies-tv' => 'film', 'food-drink' => 'utensils', 'general-knowledge' => 'bulb',
    ];

    private const TINTS = ['bg-tint-teal', 'bg-tint-coral', 'bg-tint-sun', 'bg-tint-navy'];

    public static function icon(string $slug): string
    {
        return self::ICONS[$slug] ?? 'sparkles';
    }

    public static function tint(string $slug): string
    {
        return self::TINTS[crc32($slug) % count(self::TINTS)];
    }
}
