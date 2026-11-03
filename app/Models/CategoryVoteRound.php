<?php

namespace App\Models;

use App\Enums\VoteOutcome;
use Database\Factories\CategoryVoteRoundFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A Vote-mode room's vote on the category of the next batch, after question `round` x vote_every. Open (the voting state) while closed_at is null.
 * Timestamps carry milliseconds, like the question deadlines.
 *
 * @property int $id
 * @property int $room_id
 * @property int $round 1-based
 * @property list<int> $option_ids the offered top-level categories, in display order
 * @property Carbon|null $ends_at null when there was no vote (one option)
 * @property Carbon|null $closed_at
 * @property int|null $winner_category_id
 * @property VoteOutcome|null $outcome
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['room_id', 'round', 'option_ids', 'ends_at', 'closed_at', 'winner_category_id', 'outcome'])]
class CategoryVoteRound extends Model
{
    /** @use HasFactory<CategoryVoteRoundFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'option_ids' => 'array',
            'ends_at' => 'datetime',
            'closed_at' => 'datetime',
            'winner_category_id' => 'integer',
            'outcome' => VoteOutcome::class,
        ];
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function winner(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'winner_category_id');
    }

    /** @return HasMany<CategoryVote, $this> the votes cast in this round (keyed by room and round number, so not eager-loadable) */
    public function votes(): HasMany
    {
        return $this->hasMany(CategoryVote::class, 'room_id', 'room_id')->where('category_votes.round', $this->round);
    }
}
