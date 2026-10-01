<?php

namespace App\Game;

use App\Enums\RoomStatus;
use App\Events\PlayerJoined;
use App\Models\Room;
use App\Models\RoomPlayer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/** Puts a participant (logged-in user or guest) into a room's lobby, or finds the row they already have. */
class RoomJoiner
{
    public function __construct(private readonly PlayerIdentity $identity) {}

    /**
     * @throws JoinException
     */
    public function join(string $code, string $nickname, string $locale): RoomPlayer
    {
        $room = Room::where('code', RoomCodeGenerator::normalize($code))->first() ?? throw JoinException::roomNotFound();

        // The host runs the big screen and does not play.
        $user = $this->identity->user();
        if ($user && $room->host_id === $user->id) {
            throw JoinException::isHost();
        }

        // Rejoining: the same user or device gets their row back, in any room state, never a second player.
        if ($existing = $this->identity->playerIn($room)) {
            return $existing;
        }

        match ($room->status) {
            RoomStatus::Lobby => null,
            RoomStatus::Playing => throw JoinException::alreadyStarted(),
            RoomStatus::Finished => throw JoinException::roomNotFound(),
        };

        $nickname = Str::squish($nickname);
        if ($this->nicknameTaken($room, $nickname)) {
            throw JoinException::nicknameTaken();
        }

        try {
            $player = $room->players()->create([
                ...$this->identity->attributes(),
                'nickname' => $nickname,
                'locale' => array_key_exists($locale, config('locales.supported')) ? $locale : config('app.locale'),
                'score' => 0,
                'is_ready' => false,
                'joined_at' => now(),
                // Not connected until their lobby page joins the presence channel; unseen for too long, they are dropped.
                'left_at' => now(),
            ]);
            PlayerJoined::broadcast($room, $player)->toOthers();

            return $player;
        } catch (UniqueConstraintViolationException) {
            // Two joins at once: the unique indexes decide. The same participant again is a rejoin, anything else a taken nickname.
            return $this->identity->playerIn($room) ?? throw JoinException::nicknameTaken();
        }
    }

    private function nicknameTaken(Room $room, string $nickname): bool
    {
        return $room->players()->whereRaw('LOWER(nickname) = ?', [mb_strtolower($nickname)])->exists();
    }
}
