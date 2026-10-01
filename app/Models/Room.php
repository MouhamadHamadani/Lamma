<?php

namespace App\Models;

use App\Enums\RoomStatus;
use App\Game\RoomSettings;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property int $host_id
 * @property RoomStatus $status
 * @property RoomSettings $settings
 * @property int|null $next_room_id The room that replaced this one after "Play again"
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['code', 'host_id', 'status', 'settings', 'next_room_id', 'started_at', 'finished_at'])]
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Room $room) {
            $room->status ??= RoomStatus::Lobby;
            if (! array_key_exists('settings', $room->getAttributes())) {
                $room->settings = new RoomSettings;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => RoomStatus::class,
            'settings' => RoomSettings::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Rooms still in use: in the lobby or being played.
     *
     * @param  Builder<Room>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [RoomStatus::Lobby, RoomStatus::Playing]);
    }

    /**
     * Games that ran to the end: finished, with questions, every one of them revealed. A room closed part-way is not one.
     *
     * @param  Builder<Room>  $query
     */
    public function scopeCompleted(Builder $query): void
    {
        $query->where('status', RoomStatus::Finished)
            ->whereHas('roomQuestions', fn ($questions) => $questions->reorder())
            ->whereDoesntHave('roomQuestions', fn ($questions) => $questions->reorder()->whereNull('revealed_at'));
    }

    public function isCompleted(): bool
    {
        return $this->status === RoomStatus::Finished
            && $this->roomQuestions()->exists()
            && ! $this->roomQuestions()->whereNull('revealed_at')->exists();
    }

    /** @return BelongsTo<Room, $this> */
    public function nextRoom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'next_room_id');
    }

    /** @return BelongsTo<User, $this> */
    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    /** @return HasMany<RoomPlayer, $this> */
    public function players(): HasMany
    {
        return $this->hasMany(RoomPlayer::class);
    }

    /**
     * The question on screen now: the latest one that has started (open until it is revealed, then showing its answer).
     * Null before the first question and in a lobby.
     */
    public function currentQuestion(): ?RoomQuestion
    {
        return $this->roomQuestions()->whereNotNull('started_at')->reorder('position', 'desc')->first();
    }

    /** @return HasMany<RoomQuestion, $this> */
    public function roomQuestions(): HasMany
    {
        return $this->hasMany(RoomQuestion::class)->orderBy('position');
    }
}
