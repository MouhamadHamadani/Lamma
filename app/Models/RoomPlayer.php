<?php

namespace App\Models;

use Database\Factories\RoomPlayerFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A player in a room: either a registered user (`user_id`) or a guest (`guest_token`).
 *
 * It is also Authenticatable so the "player" guard can hand it to channel authorization: a guest has no User account,
 * the lamma_guest cookie is their credential. The token never leaves the server (hidden from serialization).
 *
 * @property int $id
 * @property int $room_id
 * @property int|null $user_id
 * @property string|null $guest_token
 * @property string $nickname
 * @property string $locale
 * @property int $score
 * @property bool $is_ready
 * @property Carbon $joined_at
 * @property Carbon|null $left_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['room_id', 'user_id', 'guest_token', 'nickname', 'locale', 'score', 'is_ready', 'joined_at', 'left_at'])]
#[Hidden(['guest_token'])]
class RoomPlayer extends Model implements AuthenticatableContract
{
    /** @use HasFactory<RoomPlayerFactory> */
    use Authenticatable, HasFactory;

    /**
     * The id a presence channel knows this participant by. Prefixed so it can never equal a User id (the host and
     * logged-in players are Users), which would merge two different people into one presence member.
     */
    public function getAuthIdentifier(): string
    {
        return 'player:'.$this->getKey();
    }

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'is_ready' => 'boolean',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    /**
     * Players whose phone is connected right now (left_at is when it disconnected; a new player starts disconnected
     * until their lobby page joins the presence channel).
     *
     * @param  Builder<RoomPlayer>  $query
     */
    public function scopeConnected(Builder $query): void
    {
        $query->whereNull('left_at');
    }

    /**
     * What the screens may know about a player: never the guest token, never the account.
     *
     * @return array{id: int, nickname: string, locale: string, is_ready: bool}
     */
    public function toBroadcast(): array
    {
        return [
            'id' => $this->id,
            'nickname' => $this->nickname,
            'locale' => $this->locale,
            'is_ready' => (bool) $this->is_ready,
        ];
    }

    /**
     * Rows that count as saved: only those tied to an account show in a user's history and stats.
     * A guest's row only becomes saved once it is claimed (see ClaimGuestResults).
     *
     * @param  Builder<RoomPlayer>  $query
     */
    public function scopeSavedToAccount(Builder $query): void
    {
        $query->whereNotNull('user_id');
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<PlayerAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(PlayerAnswer::class);
    }
}
