<?php

namespace App\Game;

use Normalizer;

/**
 * Is this nickname one we do not want on a screen? The list is `config('lamma.blocked_words')` (resources/blocklist/nicknames.php, which says how to extend it).
 *
 * Both a nickname and every listed word go through the same clean-up before they are compared (see fold()), so a listed word also catches its disguised
 * forms. A 'contains' word is blocked anywhere inside the name; an 'exact' word only when it is the whole name or one of its words, which is what keeps
 * Scunthorpe, Cassandra, Nicole and Nazir playable. It never says WHICH word matched: callers show one generic message.
 */
final class NicknameFilter
{
    /** Digits and symbols read as the letters they look like (leet speak). */
    private const LOOKALIKES = ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '8' => 'b', '@' => 'a', '$' => 's', '!' => 'i', '+' => 't', 'v' => 'u'];

    /** Digits as Arabic is typed in Latin letters (Arabizi). Never real Latin letters, so 3ars can never collide with the English word "ears". */
    private const ARABIZI = ['2' => 'ʔ', '3' => 'ʕ', '5' => 'ḫ', '6' => 'ṭ', '7' => 'ħ', '8' => 'ġ', '9' => 'q'];

    /** Arabic letters folded to one form. The marks (hamza above, below, madda) are dropped separately. */
    private const ARABIC = ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ؤ' => 'و', 'ئ' => 'ي', 'ى' => 'ي', 'ی' => 'ي', 'ة' => 'ه', 'ک' => 'ك', 'ۀ' => 'ه'];

    /** Arabic-Indic and Persian digits to Western ones. */
    private const EASTERN_DIGITS = ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'];

    /** @var array<string, bool> */
    private array $verdicts = [];

    /** @var array{contains: array{latin: list<string>, arabizi: list<string>}, exact: array{latin: list<string>, arabizi: list<string>}}|null */
    private ?array $patterns = null;

    public function isBlocked(string $nickname): bool
    {
        return $this->verdicts[$nickname] ??= $this->check($nickname);
    }

    /**
     * The clean-up both sides go through: lower case, accents and Arabic marks gone, Arabic letter variants folded, digits and symbols read as letters in
     * one of two ways ('latin': leet speak, 'arabizi': Arabic typed with digits), then everything that is not a letter dropped.
     *
     * @param  'latin'|'arabizi'  $reading
     */
    public static function fold(string $text, string $reading = 'latin'): string
    {
        $text = self::clean($text);
        $text = strtr($text, $reading === 'arabizi' ? self::ARABIZI : self::LOOKALIKES);

        return (string) preg_replace('/[^\p{L}]+/u', '', $text);
    }

    /** Lower case, accents and marks removed, Arabic variants folded, Eastern digits made Western. Punctuation and spaces are still there. */
    private static function clean(string $text): string
    {
        return mb_strtolower(self::strip($text));
    }

    /** Like clean() but keeps the letter case (a capital after a small letter marks a new word: BigDick). A mark never splits a word: كسّار is one word. */
    private static function strip(string $text): string
    {
        if (class_exists(Normalizer::class)) {
            $text = Normalizer::normalize($text, Normalizer::FORM_KD) ?: $text;
        }
        $text = (string) preg_replace('/[\p{Mn}\x{0640}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}]+/u', '', $text);

        return strtr($text, self::ARABIC + self::EASTERN_DIGITS);
    }

    private function check(string $nickname): bool
    {
        $patterns = $this->patterns();

        foreach (['latin', 'arabizi'] as $reading) {
            $whole = self::fold($nickname, $reading);
            if ($whole === '') {
                continue;
            }

            foreach ($patterns['contains'][$reading] as $pattern) {
                if (preg_match($pattern, $whole) === 1) {
                    return true;
                }
            }

            $words = [$whole];
            foreach ($this->wordsOf($nickname) as $word) {
                $folded = self::fold($word, $reading);
                if ($folded !== '') {
                    $words[] = $folded;
                }
            }
            foreach ($patterns['exact'][$reading] as $pattern) {
                foreach ($words as $word) {
                    if (preg_match($pattern, $word) === 1) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * The words of a nickname: split at anything that is not a letter or digit, at a capital after a small letter (BigDick) and at the edge between
     * letters and digits (dick69), and each also without its digits.
     *
     * @return list<string>
     */
    private function wordsOf(string $nickname): array
    {
        $words = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', self::clean($nickname), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $chunk) {
            $words[] = $chunk;
            $words[] = (string) preg_replace('/\p{N}+/u', '', $chunk);
        }
        // Camel case is looked at on the original capitals (clean() lower-cased them).
        foreach (preg_split('/[^\p{L}\p{N}]+/u', self::strip($nickname), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $chunk) {
            foreach (preg_split('/(?<=\p{Ll})(?=\p{Lu})|(?<=\p{L})(?=\p{N})|(?<=\p{N})(?=\p{L})/u', $chunk, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                $words[] = self::clean($part);
            }
        }

        return array_values(array_unique(array_filter($words, fn (string $word) => $word !== '')));
    }

    /**
     * The list, compiled once: a word with a digit is compared in the Arabizi reading, any other in the plain one. Each letter becomes "this letter, any
     * number of times at least as often as the word has it", so stretched forms match and a doubled letter in the word (ass) is still required.
     *
     * @return array{contains: array{latin: list<string>, arabizi: list<string>}, exact: array{latin: list<string>, arabizi: list<string>}}
     */
    private function patterns(): array
    {
        if ($this->patterns !== null) {
            return $this->patterns;
        }

        $compiled = ['contains' => ['latin' => [], 'arabizi' => []], 'exact' => ['latin' => [], 'arabizi' => []]];
        $lists = (array) config('lamma.blocked_words', []);

        foreach (['contains', 'exact'] as $kind) {
            foreach ((array) ($lists[$kind] ?? []) as $word) {
                if (! is_string($word)) {
                    continue;
                }
                $reading = preg_match('/\d/u', self::clean($word)) === 1 ? 'arabizi' : 'latin';
                $letters = self::fold($word, $reading);
                if ($letters === '') {
                    continue;
                }

                $body = '';
                foreach (self::runs($letters) as [$letter, $count]) {
                    $body .= preg_quote($letter, '/').'{'.$count.',}';
                }
                $compiled[$kind][$reading][] = $kind === 'exact' ? '/^'.$body.'$/u' : '/'.$body.'/u';
            }
        }

        return $this->patterns = $compiled;
    }

    /**
     * @return list<array{string, int}> the letters of a word with how many times each run repeats
     */
    private static function runs(string $letters): array
    {
        $runs = [];
        foreach (mb_str_split($letters) as $letter) {
            if ($runs !== [] && $runs[array_key_last($runs)][0] === $letter) {
                $runs[array_key_last($runs)][1]++;
            } else {
                $runs[] = [$letter, 1];
            }
        }

        return $runs;
    }
}
