<?php

namespace App\Models;

use App\Game\PlayerPhoto;
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
 * @property string|null $avatar_path The selfie's file on the player_photos disk (App\Game\PlayerPhoto); per game, never a profile
 * @property Carbon|null $avatar_updated_at
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

    protected static function booted(): void
    {
        // A player who leaves or is removed takes their selfie with them. (A deleted room's rows go by the database, see Room::booted.)
        static::deleting(fn (RoomPlayer $player) => app(PlayerPhoto::class)->forget($player));
    }

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
            'avatar_updated_at' => 'datetime',
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

    /** The URL of the selfie, or null without one. The path is random and unguessable; there is no listing route. */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path === null ? null : app(PlayerPhoto::class)->url($this->avatar_path);
    }

    /**
     * What a game screen may know about a player: id, nickname, locale and, only when the room shows photos, the selfie's URL (null without one).
     *
     * @return array{id: int, nickname: string, locale: string, avatar_url?: string|null}
     */
    public function toRanked(bool $photos): array
    {
        return [
            'id' => $this->id,
            'nickname' => $this->nickname,
            'locale' => $this->locale,
            ...($photos ? ['avatar_url' => $this->avatarUrl()] : []),
        ];
    }

    /**
     * What the screens may know about a player: never the guest token, never the account.
     *
     * @return array{id: int, nickname: string, locale: string, is_ready: bool, avatar_url?: string|null}
     */
    public function toBroadcast(): array
    {
        return [
            ...$this->toRanked($this->room->settings->showPhotos),
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
