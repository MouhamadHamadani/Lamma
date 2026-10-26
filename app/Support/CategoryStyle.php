<?php

namespace App\Support;

/**
 * Line icon and soft tint for a category tile, chosen from its slug (a subcategory has its own icon but wears its parent's tint: pass the
 * parent's slug to tintName()/tint(), see Category::tintSlug()). The `icon` column holds emoji
 * (seeded that way), which the Lamma design forbids, so it is ignored here.
 */
final class CategoryStyle
{
    private const ICONS = [
        'geography' => 'globe', 'science' => 'flask', 'sports' => 'ball', 'history' => 'landmark',
        'movies-tv' => 'film', 'food-drink' => 'utensils', 'general-knowledge' => 'bulb',
        'animals-nature' => 'leaf', 'arab-world' => 'compass',
        // Subcategories of sports and general knowledge.
        'football' => 'ball', 'basketball' => 'basketball', 'rugby' => 'rugby', 'tennis' => 'racket', 'motorsport' => 'flag',
        'olympics' => 'rings', 'combat-sports' => 'glove',
        'everyday-life' => 'coffee', 'words-language' => 'book', 'inventions' => 'cog', 'human-body' => 'heart',
        'landmarks' => 'pin', 'arts-music' => 'music',
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
