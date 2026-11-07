<?php

namespace App\Game;

use RuntimeException;

/** Why a question's picture was refused. Shown to the admin, who works in English. */
class QuestionImageException extends RuntimeException
{
    public static function invalid(): self
    {
        return new self('That file is not a JPEG, PNG or WebP picture.');
    }

    public static function tooBig(): self
    {
        return new self('The picture is larger than '.(QuestionImage::MAX_BYTES / 1024 / 1024).' MB.');
    }

    public static function tooLarge(): self
    {
        return new self('The picture is too large: at most '.QuestionImage::MAX_SIDE.' pixels wide or tall, and '.(QuestionImage::MAX_PIXELS / 1_000_000).' megapixels.');
    }

    public static function tooSmall(): self
    {
        return new self('The picture is too small: at least '.QuestionImage::MIN_SIDE.' pixels wide and tall.');
    }

    public static function unavailable(): self
    {
        return new self('The picture could not be saved on this server.');
    }
}
