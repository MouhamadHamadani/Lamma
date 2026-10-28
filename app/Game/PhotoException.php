<?php

namespace App\Game;

use RuntimeException;

/** A selfie could not be used. The message is for the player (translated, never a detail of the file). */
class PhotoException extends RuntimeException
{
    public static function invalid(): self
    {
        return new self(__("That photo didn't work. Please take another one."));
    }

    public static function tooLarge(): self
    {
        return new self(__('That photo is too big. Please take another one.'));
    }

    public static function off(): self
    {
        return new self(__('Photos are turned off in this room.'));
    }

    public static function unavailable(): self
    {
        return new self(__("We couldn't save your photo. You can skip it and play without."));
    }

    public static function tooMany(int $seconds): self
    {
        return new self(__('Too many photos. Please try again in :seconds seconds.', ['seconds' => $seconds]));
    }
}
