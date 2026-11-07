<?php

namespace App\Game;

/**
 * The number a player types for a closest-number question, made safe to use, and a number made fit to show.
 *
 * parse() never trusts the client. Arabic-Indic digits (٠-٩, and the Persian ۰-۹), the Arabic decimal separator ٫ and thousands separator ٬, spaces and the
 * invisible direction marks a right-to-left keyboard adds all become plain Western digits first. Then comes the one judgement call, a single "," or ".":
 *
 *   - both appear: the LAST one is the decimal point and the other is a thousands separator ("1,234.5" and "1.234,5" are both 1234.5);
 *   - one "." alone is a decimal point ("1.5", and "1.234" is 1.234);
 *   - several of the same are thousands separators and must be in groups of three ("1.234.567");
 *   - one "," alone is a thousands separator when exactly three digits follow it ("8,849") and a decimal point otherwise ("3,5").
 *
 * Anything else (letters, "1e5", "12abc", two decimal points, nothing at all, more than 15 digits) is not a number: null. The result is rounded to
 * four decimals, the precision the database keeps, and never beyond a trillion either way.
 */
final class GuessNumber
{
    public const MAX = 1_000_000_000_000.0;

    /** How much the player may type: a number this long is not a guess. */
    public const MAX_LENGTH = 40;

    private const MAX_DIGITS = 15;

    public static function parse(string|int|float|null $input): ?float
    {
        if (is_int($input) || is_float($input)) {
            return is_finite((float) $input) && abs((float) $input) <= self::MAX ? self::clean((float) $input) : null;
        }
        if ($input === null || mb_strlen($input) > self::MAX_LENGTH) {
            return null;
        }

        $text = self::western($input);
        if (preg_match('/^([+-]?)([0-9.,]+)$/', $text, $parts) !== 1) {
            return null;
        }
        [, $sign, $body] = $parts;

        $number = self::plain($body);
        if ($number === null || strlen(str_replace('.', '', $number)) > self::MAX_DIGITS) {
            return null;
        }

        $value = (float) $number * ($sign === '-' ? -1 : 1);

        return abs($value) <= self::MAX ? self::clean($value) : null;
    }

    /** "1969", "8849", "3.5", and "384,400" from 10,000 up: Western digits, no zeros after the point, a thousands comma only where it helps (never in a year). */
    public static function format(float $value): string
    {
        $value = self::clean($value);
        $text = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
        $decimals = str_contains($text, '.') ? strlen(substr($text, strpos($text, '.') + 1)) : 0;

        return abs($value) >= 10000 ? number_format($value, $decimals, '.', ',') : $text;
    }

    /** Western digits and plain ASCII punctuation; spaces and invisible marks gone. */
    private static function western(string $input): string
    {
        $text = preg_replace('/[\s\x{00A0}\x{2009}\x{202F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{061C}\x{FEFF}\x{0027}\x{2019}]/u', '', $input) ?? '';

        $map = [
            '٫' => '.', '٬' => '', '،' => ',', '−' => '-', '﹣' => '-', '－' => '-', '＋' => '+',
        ];
        foreach (['٠١٢٣٤٥٦٧٨٩', '۰۱۲۳۴۵۶۷۸۹', '０１２３４５６７８９'] as $digits) {
            foreach (mb_str_split($digits) as $value => $digit) {
                $map[$digit] = (string) $value;
            }
        }

        return strtr($text, $map);
    }

    /** A string of digits, dots and commas as plain digits with at most one ".", or null when the separators make no sense. */
    private static function plain(string $body): ?string
    {
        $dots = substr_count($body, '.');
        $commas = substr_count($body, ',');

        if ($dots > 0 && $commas > 0) {
            $decimal = strrpos($body, '.') > strrpos($body, ',') ? '.' : ',';
            $group = $decimal === '.' ? ',' : '.';
            $halves = explode($decimal, $body);

            return count($halves) === 2 && self::grouped($halves[0], $group) !== null && ! str_contains($halves[1], $group)
                ? self::decimal((string) self::grouped($halves[0], $group), $halves[1])
                : null;
        }

        if ($dots > 1 || $commas > 1) {
            return self::grouped($body, $dots > 1 ? '.' : ',');
        }

        if ($commas === 1) {
            [$before, $after] = explode(',', $body);
            if (strlen($after) === 3 && $before !== '' && strlen($before) <= 3 && ltrim($before, '0') !== '') {
                return $before.$after;
            }

            return self::decimal($before, $after);
        }

        return $dots === 1 ? self::decimal(...explode('.', $body)) : (ctype_digit($body) ? $body : null);
    }

    /** "1,234,567" with "," as the group separator: the digits, or null when the groups are not 1-3 digits then 3 each. */
    private static function grouped(string $text, string $separator): ?string
    {
        if (! str_contains($text, $separator)) {
            return ctype_digit($text) ? $text : null;
        }

        return preg_match('/^[0-9]{1,3}(?:'.preg_quote($separator, '/').'[0-9]{3})+$/', $text) === 1 ? str_replace($separator, '', $text) : null;
    }

    private static function decimal(string $whole, string $fraction): ?string
    {
        if (($whole === '' && $fraction === '') || ! ctype_digit($whole.'0') || ! ctype_digit($fraction.'0')) {
            return null;
        }

        return ($whole === '' ? '0' : $whole).($fraction === '' ? '' : '.'.$fraction);
    }

    private static function clean(float $value): float
    {
        $value = round($value, 4);

        return $value == 0.0 ? 0.0 : $value; // no negative zero
    }
}
