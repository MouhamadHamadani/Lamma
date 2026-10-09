<?php

namespace App\Support;

/**
 * The brand files and sounds that ship as PLACEHOLDERS (config/lamma.php 'placeholders': path under public/ => sha256 of the placeholder).
 * They are replaced later by the official files under the same names. A file whose hash still matches is a placeholder, which
 * `php artisan lamma:doctor` reports as a warning (never a failure).
 */
final class BrandFiles
{
    /** sha256 of a file under public/. An SVG is hashed with LF line endings, so a Windows checkout and a Linux server agree. Null when missing. */
    public static function hash(string $relativePath): ?string
    {
        $file = public_path($relativePath);
        if (! is_file($file)) {
            return null;
        }

        $contents = (string) file_get_contents($file);
        if (str_ends_with($relativePath, '.svg')) {
            $contents = str_replace("\r\n", "\n", $contents);
        }

        return hash('sha256', $contents);
    }

    /** @return list<string> the paths that are still the placeholder (or missing, marked so) */
    public static function stillPlaceholders(): array
    {
        $still = [];

        foreach ((array) config('lamma.placeholders') as $path => $placeholderHash) {
            $hash = self::hash($path);

            if ($hash === null) {
                $still[] = "{$path} (missing)";
            } elseif ($hash === $placeholderHash) {
                $still[] = $path;
            }
        }

        return $still;
    }
}
