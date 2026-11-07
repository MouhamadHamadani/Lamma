<?php

use App\Game\GuessNumber;

describe('reading what a player typed', function () {
    it('reads plain Western digits', function (string $typed, float $expected) {
        expect(GuessNumber::parse($typed))->toBe($expected);
    })->with([
        'a year' => ['1969', 1969.0],
        'spaces around it' => ['  1969 ', 1969.0],
        'zero' => ['0', 0.0],
        'leading zeros' => ['007', 7.0],
        'a decimal' => ['3.5', 3.5],
        'a leading point' => ['.5', 0.5],
        'a trailing point' => ['5.', 5.0],
        'plus' => ['+5', 5.0],
        'minus' => ['-5', -5.0],
        'the typographic minus' => ["\u{2212}5", -5.0],
        'rounded to four decimals' => ['1.23456', 1.2346],
        'a trillion, the largest' => ['1000000000000', 1.0E12],
    ]);

    it('turns Arabic-Indic, Persian and full-width digits into Western ones', function (string $typed, float $expected) {
        expect(GuessNumber::parse($typed))->toBe($expected);
    })->with([
        'Arabic-Indic' => ['١٩٦٩', 1969.0],
        'Persian' => ['۱۹۶۹', 1969.0],
        'full-width' => ['８８４９', 8849.0],
        'mixed with Western' => ['19٦9', 1969.0],
        'with the Arabic decimal separator' => ['٣٫٥', 3.5],
        'with the Arabic thousands separator' => ['٣٨٤٬٤٠٠', 384400.0],
        'with the Arabic comma as thousands' => ['٣٨٤،٤٠٠', 384400.0],
        'with the Arabic comma as a decimal point' => ['٣،٥', 3.5],
        'a minus in front of Arabic digits' => ['-٥', -5.0],
    ]);

    it('ignores the invisible marks a right-to-left keyboard adds, and every kind of space', function (string $typed) {
        expect(GuessNumber::parse($typed))->toBe(1969.0);
    })->with([
        'right-to-left mark' => ["\u{200F}١٩٦٩\u{200F}"],
        'left-to-right mark' => ["\u{200E}1969"],
        'embedding marks' => ["\u{202B}1969\u{202C}"],
        'isolates' => ["\u{2066}1969\u{2069}"],
        'a non-breaking space' => ["1\u{00A0}969"],
        'a thin space' => ["1\u{2009}969"],
        'a zero-width space' => ["19\u{200B}69"],
        'a tab and a newline' => ["\t1969\n"],
        'an apostrophe' => ["1'969"],
    ]);

    it('knows a thousands separator from a decimal point', function (string $typed, float $expected) {
        expect(GuessNumber::parse($typed))->toBe($expected);
    })->with([
        'a comma before three digits' => ['8,849', 8849.0],
        'a comma and a decimal point' => ['1,234.5', 1234.5],
        'a point and a decimal comma' => ['1.234,5', 1234.5],
        'a comma before one digit' => ['3,5', 3.5],
        'a comma before two digits' => ['3,25', 3.25],
        'a comma after zero is a decimal' => ['0,500', 0.5],
        'many commas' => ['1,234,567', 1234567.0],
        'many points' => ['1.234.567', 1234567.0],
        'a single point is always a decimal point' => ['8.849', 8.849],
        'a big number with a comma' => ['384,400', 384400.0],
        'a long number with the wrong comma place is a decimal' => ['1234,567', 1234.567],
    ]);

    it('rejects anything that is not a number', function (?string $typed) {
        expect(GuessNumber::parse($typed))->toBeNull();
    })->with([
        'nothing' => [''],
        'only spaces' => ['   '],
        'null' => [null],
        'letters' => ['abc'],
        'digits then letters' => ['12abc'],
        'an exponent' => ['1e5'],
        'hexadecimal' => ['0x1F'],
        'not a number' => ['NaN'],
        'infinity' => ['INF'],
        'only a point' => ['.'],
        'only a comma' => [','],
        'a lone sign' => ['-'],
        'two signs' => ['--5'],
        'a sign at the end' => ['5-'],
        'two decimal points' => ['1..2'],
        'three parts with points' => ['1.2.3'],
        'commas in odd groups' => ['1,2,3'],
        'commas with a group of two' => ['1,23,456'],
        'a comma and a point the wrong way round' => ['1,2.3,4'],
        'Arabic letters' => ['ألف'],
        'a sentence' => ['about 1969'],
        'markup' => ['<b>1</b>'],
        'a script' => ['1969<script>alert(1)</script>'],
        'sixteen digits' => ['1 000 000 000 000 000'],
        'a number past a trillion' => ['1000000000001'],
        'a very long number' => [str_repeat('9', 41)],
        'a very long number even of zeros' => [str_repeat('0', 41)],
    ]);

    it('takes a number as it is, unless it is out of range', function () {
        expect(GuessNumber::parse(1969))->toBe(1969.0)
            ->and(GuessNumber::parse(3.5))->toBe(3.5)
            ->and(GuessNumber::parse(-2))->toBe(-2.0)
            ->and(GuessNumber::parse(0.0))->toBe(0.0)
            ->and(GuessNumber::parse(NAN))->toBeNull()
            ->and(GuessNumber::parse(INF))->toBeNull()
            ->and(GuessNumber::parse(2.0E12))->toBeNull();
    });

    it('never answers negative zero', function () {
        expect(GuessNumber::parse('-0'))->toBe(0.0)->and(GuessNumber::parse(-0.0))->toBe(0.0);
    });
});

describe('showing a number', function () {
    it('shows it in Western digits, a thousands comma only from 10,000, and no zeros after the point', function (float $value, string $shown) {
        expect(GuessNumber::format($value))->toBe($shown);
    })->with([
        'a year stays a year' => [1969.0, '1969'],
        'a small whole number' => [2.0, '2'],
        'a decimal' => [3.5, '3.5'],
        'four decimals' => [1969.1234, '1969.1234'],
        'the largest without a comma' => [9999.0, '9999'],
        'ten thousand' => [10000.0, '10,000'],
        'a long distance' => [384400.0, '384,400'],
        'a long decimal' => [12345.5, '12,345.5'],
        'negative' => [-250.0, '-250'],
        'negative and long' => [-12345.0, '-12,345'],
        'zero' => [0.0, '0'],
        'negative zero' => [-0.0, '0'],
    ]);
});
