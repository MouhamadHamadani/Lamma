<?php

namespace App\Game;

use App\Enums\HostScreenLocale;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

/**
 * Who may join the room's presence channel, and what the other members learn about them.
 * Members are the room's host and the room's participants, nobody else.
 */
class RoomPresence
{
    public function __construct(private readonly PlayerIdentity $identity) {}

    /** The channel a room's lobby listens on (without the "presence-" prefix Echo and Reverb add). */
    public static function channel(string $code): string
    {
        return 'room.'.$code;
    }

    /**
     * Member info for the authenticated party, or null when they may not join.
     * $user is whatever the channel's guards produced: a User (web guard) or a RoomPlayer (player guard).
     *
     * @return array{room_player_id: int|null, nickname: string, locale: string, is_host: bool}|null
     */
    public function memberFor(string $code, ?Authenticatable $user): ?array
    {
        $room = Room::where('code', Str::upper($code))->first();
        if (! $room || ! $user) {
            return null;
        }

        if ($user instanceof User && $room->host_id === $user->id) {
            return [
                'room_player_id' => null,
                'nickname' => $user->name,
                'locale' => $room->settings->hostScreenLocale === HostScreenLocale::Ar ? 'ar' : 'en',
                'is_host' => true,
            ];
        }

        $player = match (true) {
            $user instanceof RoomPlayer => $user->room_id === $room->id ? $user : null,
            $user instanceof User => $this->identity->playerIn($room),
            default => null,
        };

        return $player ? [
            'room_player_id' => $player->id,
            'nickname' => $player->nickname,
            'locale' => $player->locale,
            'is_host' => false,
        ] : null;
    }

    /**
     * The "player" guard: a guest's row, found from the lamma_guest cookie. A guest can be in several rooms, so the
     * channel being authorized (channel_name=presence-room.CODE) picks the row; with no room named it is the latest.
     */
    public function guestPlayerFor(?string $channelName): ?RoomPlayer
    {
        $token = $this->identity->guestToken();
        if ($token === null) {
            return null;
        }

        $code = str_contains((string) $channelName, 'room.') ? Str::upper(Str::after((string) $channelName, 'room.')) : null;

        return RoomPlayer::query()
            ->where('guest_token', $token)
            ->when($code, fn ($query) => $query->whereHas('room', fn ($room) => $room->where('code', $code)))
            ->latest('id')
            ->first();
    }
}
