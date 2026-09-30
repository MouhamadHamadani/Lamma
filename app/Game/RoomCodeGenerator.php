<?php

namespace App\Game;

use App\Models\Room;
use RuntimeException;

class RoomCodeGenerator
{
    /** No look-alikes: 0/O and 1/I/L are left out. */
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const LENGTH = 6;

    private const MAX_ATTEMPTS = 10;

    /**
     * A code not currently used by any room. The unique index on rooms.code
     * stays the final guard against a race between generate() and insert.
     */
    public function generate(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $code = $this->randomCode();

            if (! Room::where('code', $code)->exists()) {
                return $code;
            }
        }

        throw new RuntimeException('Could not generate a unique room code.');
    }

    protected function randomCode(): string
    {
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }
}
