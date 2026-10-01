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

    private const TINTS = ['teal', 'coral', 'sun', 'navy'];

    public static function icon(string $slug): string
    {
        return self::ICONS[$slug] ?? 'sparkles';
    }

    /** The tint's name (teal, coral, sun, navy): also the chip tone and what the question payload carries. */
    public static function tintName(string $slug): string
    {
        return self::TINTS[crc32($slug) % count(self::TINTS)];
    }

    /** The background utility for that tint. */
    public static function tint(string $slug): string
    {
        return 'bg-tint-'.self::tintName($slug);
    }
}
