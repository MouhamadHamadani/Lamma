<?php

namespace App\Support;

/**
 * Numbers and names inside a sentence. A score, a count or a nickname always reads left to right, but the sentence around it follows the
 * page's direction, so only the value is isolated: "700 نقطة" (a bdi around 700), never a dir="ltr" around the whole phrase, which would
 * scramble the order of words and numbers in Arabic ("4 من 5").
 */
final class Isolate
{
    /** The value wrapped in <bdi dir="ltr">, escaped. Pass it as a replacement to a translation and print the result with {!! !!}. */
    public static function ltr(int|string $value): string
    {
        return '<bdi dir="ltr">'.e((string) $value).'</bdi>';
    }
}
