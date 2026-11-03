<?php

namespace App\Models;

use App\Enums\GameMode;
use App\Enums\RoomStatus;
use App\Game\PlayerPhoto;
use App\Game\RoomSettings;
use App\Game\VoteSettings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property int|null $host_id
 * @property RoomStatus $status
 * @property GameMode $game_mode
 * @property int|null $vote_every Vote mode: the players vote on the next category after every this many questions
 * @property int|null $first_category_id Vote mode: the category of the first batch
 * @property RoomSettings $settings
 * @property int|null $next_room_id The room that replaced this one after "Play again"
 * @property Carbon|null $started_at
 * @property CarbonImmutable|null $countdown_ends_at When question 1 opens (3 - 2 - 1 - Go! after Start game), with milliseconds
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['code', 'host_id', 'status', 'game_mode', 'vote_every', 'first_category_id', 'settings', 'next_room_id', 'started_at', 'countdown_ends_at', 'finished_at'])]
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Room $room) {
            $room->status ??= RoomStatus::Lobby;
            $room->game_mode ??= GameMode::Classic;
            if (! array_key_exists('settings', $room->getAttributes())) {
                $room->settings = new RoomSettings;
            }
        });

        // The players' rows go with the room (a database cascade, no model events), so their selfies are swept here.
        static::deleting(fn (Room $room) => app(PlayerPhoto::class)->purgeFolder($room->id));
    }

    protected function casts(): array
    {
        return [
            'status' => RoomStatus::class,
            'game_mode' => GameMode::class,
            'vote_every' => 'integer',
            'first_category_id' => 'integer',
            'settings' => RoomSettings::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * The moment question 1 opens. The model's date format keeps whole seconds, so this one is written with milliseconds, like the question deadlines.
     *
     * @return Attribute<CarbonImmutable|null, CarbonInterface|null>
     */
    protected function countdownEndsAt(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? null : CarbonImmutable::parse($value),
            set: fn (?CarbonInterface $value) => $value?->setTimezone(date_default_timezone_get())->format('Y-m-d H:i:s.v'),
        );
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

    /** Nicknames are unique in a room, ignoring case (a unique index backs this up). $exceptPlayerId lets a player keep their own name. */
    public function hasNickname(string $nickname, ?int $exceptPlayerId = null): bool
    {
        return $this->players()
            ->whereRaw('LOWER(nickname) = ?', [mb_strtolower($nickname)])
            ->when($exceptPlayerId, fn ($query) => $query->whereKeyNot($exceptPlayerId))
            ->exists();
    }

    /** @return HasOne<Room, $this> the finished room this one replaced after "Play again" (so this lobby is a rematch), if any */
    public function previous(): HasOne
    {
        return $this->hasOne(self::class, 'next_room_id');
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

    public function isVote(): bool
    {
        return $this->game_mode === GameMode::Vote;
    }

    /**
     * How long the game is. A Vote-mode room adds its questions a batch at a time, so this is the host's chosen total from the start; a Classic
     * room drew every question at the start.
     */
    public function totalQuestions(): int
    {
        return $this->isVote() ? $this->settings->questionCount : $this->roomQuestions()->count();
    }

    /**
     * Vote mode: the category of each batch so far, in order (the first category, then each vote's winner; a category can come back in a new cycle).
     *
     * @return list<int>
     */
    public function playedCategories(): array
    {
        $winners = $this->voteRounds()->whereNotNull('winner_category_id')->pluck('winner_category_id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_filter([(int) $this->first_category_id, ...$winners]));
    }

    /**
     * What this game is about, for labels (My games, the admin): the host's choice in Classic; in Vote mode the categories actually played, not the pool.
     *
     * @return list<int>
     */
    public function categoryIds(): array
    {
        return $this->isVote() ? array_values(array_unique($this->playedCategories())) : $this->settings->categoryIds;
    }

    /** The Vote-mode setup (for Play again), or null for a Classic room. */
    public function voteSettings(): ?VoteSettings
    {
        if (! $this->isVote() || $this->first_category_id === null) {
            return null;
        }

        /** @var list<int> $pool */
        $pool = $this->pool()->orderBy('categories.id')->pluck('categories.id')->map(fn ($id) => (int) $id)->all();

        return new VoteSettings($this->first_category_id, $pool, (int) $this->vote_every);
    }

    /** @return BelongsTo<Category, $this> Vote mode: the category of the first batch */
    public function firstCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'first_category_id');
    }

    /** @return BelongsToMany<Category, $this> Vote mode: the top-level categories the players vote between */
    public function pool(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'room_category_pool');
    }

    /** @return HasMany<CategoryVoteRound, $this> */
    public function voteRounds(): HasMany
    {
        return $this->hasMany(CategoryVoteRound::class)->orderBy('round');
    }
}
