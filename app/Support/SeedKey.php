<?php

namespace App\Support;

/**
 * The stable identity of a shipped question: a hash of its English text (lower case, single spaces). ContentSeeder keeps it in
 * `questions.seed_key`, so running it again adds the new questions and never duplicates one, even after an admin has edited the text.
 */
final class SeedKey
{
    public static function for(string $english): string
    {
        return sha1(mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $english))));
    }
}
