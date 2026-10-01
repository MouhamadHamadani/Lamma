<?php

namespace App\Models;

use Database\Factories\RoomPlayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A player in a room: either a registered user (`user_id`) or a guest (`guest_token`).
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
class RoomPlayer extends Model
{
    /** @use HasFactory<RoomPlayerFactory> */
    use HasFactory;

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
