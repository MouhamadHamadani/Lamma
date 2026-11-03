<?php

namespace App\Game;

use App\Enums\VoteOutcome;
use App\Models\Category;
use App\Models\CategoryVoteRound;
use App\Models\Question;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * The rules of Vote mode. The host picks the first category and a pool of top-level categories; the game is played in batches of `vote_every`
 * questions (the last one shorter when the total is not a multiple), and before each batch after the first the players vote between up to three
 * pool categories not played yet in this cycle. Voting is on top-level categories: a winner's questions come from all of its subcategories.
 *
 * This class holds the rules (which categories to offer, who won, which questions to add); GameEngine runs the transitions under its lock and does
 * the broadcasting, exactly as it does for questions.
 */
class CategoryVote
{
    /** How many categories one vote offers at most. */
    public const OPTIONS = 3;

    public function __construct(private readonly QuestionPool $pool, private readonly QuestionPicker $picker) {}

    /** How long a vote stays open (lamma.vote_seconds). */
    public static function seconds(): int
    {
        return (int) config('lamma.vote_seconds', 10);
    }

    /** How long the result stays on screen before the next question (lamma.vote_result_seconds). */
    public static function resultSeconds(): int
    {
        return (int) config('lamma.vote_result_seconds', 3);
    }

    /** How many votes a game of $total questions voting every $every has: one before each batch after the first. */
    public static function voteCount(int $total, int $every): int
    {
        return $every > 0 && $every < $total ? intdiv($total - 1, $every) : 0;
    }

    /**
     * Playable questions per pool category (active, in Arabic and English, at the chosen difficulty, a top-level id counting its subcategories).
     *
     * @param  list<int>  $categoryIds
     * @return array<int, int> category id => questions
     */
    public function available(RoomSettings $settings, array $categoryIds): array
    {
        return collect($categoryIds)->mapWithKeys(fn (int $id) => [$id => $this->pool->available($this->only($settings, [$id]))])->all();
    }

    /**
     * The pool categories that cannot supply a full batch (the first one plays the first batch, any other may win a vote): category id => how many
     * questions it has. Empty when the setup works.
     *
     * @return array<int, int>
     */
    public function shortfalls(RoomSettings $settings, VoteSettings $vote): array
    {
        $batch = min($vote->every, $settings->questionCount);

        return array_filter($this->available($settings, $vote->poolIds), fn (int $count) => $count < $batch);
    }

    /** All playable questions in the pool: a vote game needs at least its total, or a category that keeps winning could run the game short. */
    public function poolTotal(RoomSettings $settings, VoteSettings $vote): int
    {
        return $this->pool->available($this->only($settings, $vote->poolIds));
    }

    /** A vote comes after this question: the end of a batch that is not the last. */
    public function due(Room $room, int $position): bool
    {
        $every = (int) $room->vote_every;

        return $room->isVote() && $every > 0 && $position % $every === 0 && $position < $room->totalQuestions();
    }

    /** How many questions the batch after $afterPosition has: vote_every, or what is left of the game. */
    public function batchSize(Room $room, int $afterPosition): int
    {
        return max(0, min((int) $room->vote_every, $room->totalQuestions() - $afterPosition));
    }

    /** The vote that comes after this question, once it has been opened. */
    public function roundAfter(Room $room, int $position): ?CategoryVoteRound
    {
        return $this->due($room, $position) ? $room->voteRounds()->where('round', intdiv($position, (int) $room->vote_every))->first() : null;
    }

    /** The vote under way, if any (there is never more than one). */
    public function openRound(Room $room): ?CategoryVoteRound
    {
        return $room->voteRounds()->whereNull('closed_at')->first();
    }

    /** The question the vote comes after. */
    public function positionOf(Room $room, CategoryVoteRound $round): int
    {
        return $round->round * (int) $room->vote_every;
    }

    /** When a vote may close on time alone: its deadline plus the same grace as an answer. */
    public function dueAt(CategoryVoteRound $round): CarbonImmutable
    {
        return CarbonImmutable::instance($round->ends_at ?? now())->addMilliseconds(GameEngine::ANSWER_GRACE_MS);
    }

    /**
     * Up to three pool categories at random, none played yet in this cycle. When fewer than three are left the vote offers what is left; when none
     * is, a new cycle starts: every pool category except the one just played.
     *
     * @return list<int>
     */
    public function offer(Room $room): array
    {
        $pool = $room->pool()->active()->topLevel()->pluck('categories.id')->map(fn ($id) => (int) $id)->all();
        $played = $room->playedCategories();

        // Replay the game so far: a cycle ends when every pool category has been played, and the next one starts from nothing.
        $used = [];
        foreach ($played as $id) {
            if (array_diff($pool, $used) === []) {
                $used = [];
            }
            $used[] = $id;
        }

        $eligible = array_diff($pool, $used) ?: array_diff($pool, [end($played)]) ?: array_slice($played, -1);

        return array_values(collect($eligible)->shuffle()->take(self::OPTIONS)->all());
    }

    /**
     * Open the vote after this question. With only one category to offer there is nothing to vote on: the round is closed at once with it as the
     * winner (OnlyOption) and its batch is drawn, so the game goes straight on. Runs inside GameEngine's lock.
     */
    public function open(Room $room, int $position): CategoryVoteRound
    {
        $options = $this->offer($room);
        $single = count($options) === 1;

        $round = $room->voteRounds()->create([
            'round' => intdiv($position, (int) $room->vote_every),
            'option_ids' => $options,
            'ends_at' => $single ? null : now()->addSeconds(self::seconds()),
        ]);

        if ($single) {
            $this->settle($room, $round, $options[0], VoteOutcome::OnlyOption);
        }

        return $round;
    }

    /**
     * Votes per offered category, in display order (every option present, 0 when nobody chose it).
     *
     * @return array<int, int>
     */
    public function counts(CategoryVoteRound $round): array
    {
        $votes = $round->votes()->pluck('category_id')->countBy();

        return collect($round->option_ids)->mapWithKeys(fn (int $id) => [$id => (int) ($votes[$id] ?? 0)])->all();
    }

    /**
     * How many have voted, and how many are expected to: the connected players plus anyone who voted and then dropped (like answers).
     *
     * @return array{voted: int, expected: int}
     */
    public function progress(Room $room, CategoryVoteRound $round): array
    {
        $voted = $round->votes()->pluck('room_player_id');

        return [
            'voted' => $voted->count(),
            'expected' => $room->players()->where(fn ($query) => $query->whereNull('left_at')->orWhereIn('id', $voted))->count(),
        ];
    }

    /** Every connected player has voted (and there is at least one). Disconnected players are not waited for. */
    public function everyoneVoted(Room $room, CategoryVoteRound $round): bool
    {
        $connected = $room->players()->connected()->pluck('id');

        return $connected->isNotEmpty() && $connected->diff($round->votes()->pluck('room_player_id'))->isEmpty();
    }

    /**
     * Close the vote: count, pick the winner (a random one among a tie, or among every option when nobody voted), and add the winner's batch.
     * Runs inside GameEngine's lock, on a round it has checked is still open.
     */
    public function close(Room $room, CategoryVoteRound $round): CategoryVoteRound
    {
        $counts = collect($this->counts($round));
        $top = $counts->filter(fn (int $votes) => $votes === $counts->max())->keys();

        [$winner, $outcome] = match (true) {
            $counts->sum() === 0 => [$counts->keys()->random(), VoteOutcome::NoVotes],
            $top->count() > 1 => [$top->random(), VoteOutcome::Tie],
            default => [$top->first(), VoteOutcome::Votes],
        };

        return $this->settle($room, $round, (int) $winner, $outcome);
    }

    /**
     * Add up to $count new questions from this category (all of its subcategories), never one already in this room, at the room's difficulty,
     * translated, avoiding the host's last rooms when there is a choice (QuestionPicker). If the category runs short (content changed since the room
     * was set up) the rest comes from the other pool categories, so the game still reaches its total. Returns how many were added.
     */
    public function drawBatch(Room $room, int $categoryId, int $count): int
    {
        $have = array_values($room->roomQuestions()->pluck('question_id')->map(fn ($id) => (int) $id)->all());
        $questions = $this->picker->pickFrom($room, [$categoryId], $count, $have);

        if ($questions->count() < $count) {
            $others = array_values($room->pool()->pluck('categories.id')->map(fn ($id) => (int) $id)->reject(fn (int $id) => $id === $categoryId)->all());
            Log::warning('Vote mode: a category ran short of questions; the rest of its batch comes from the other pool categories.', [
                'room_id' => $room->id, 'category_id' => $categoryId, 'needed' => $count, 'found' => $questions->count(),
            ]);
            $taken = [...$have, ...$questions->map(fn (Question $question) => $question->id)->all()];
            $questions = $questions->concat($this->picker->pickFrom($room, $others, $count - $questions->count(), $taken));
        }

        $position = (int) $room->roomQuestions()->reorder()->max('position');
        foreach ($questions->values() as $index => $question) {
            $room->roomQuestions()->create([
                'question_id' => $question->id,
                'position' => $position + $index + 1,
                // Shuffled once, here, like a Classic game's: every screen shows the same A, B, C, D.
                'option_order' => $question->options->pluck('id')->shuffle()->values()->all(),
            ]);
        }

        return $questions->count();
    }

    private function settle(Room $room, CategoryVoteRound $round, int $winner, VoteOutcome $outcome): CategoryVoteRound
    {
        $round->update(['closed_at' => now(), 'winner_category_id' => $winner, 'outcome' => $outcome]);
        $this->drawBatch($room, $winner, $this->batchSize($room, $this->positionOf($room, $round)));

        return $round;
    }

    /** @param list<int> $categoryIds */
    private function only(RoomSettings $settings, array $categoryIds): RoomSettings
    {
        $only = clone $settings;
        $only->categoryIds = $categoryIds;

        return $only;
    }

    /**
     * The names of these categories, in the given language, in the order given (for messages and labels).
     *
     * @param  list<int>  $ids
     * @return array<int, string> id => name
     */
    public static function names(array $ids, ?string $locale = null): array
    {
        $categories = Category::query()->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->filter(fn (int $id) => $categories->has($id))
            ->mapWithKeys(fn (int $id) => [$id => $locale ? $categories[$id]->getTranslation('name', $locale) : $categories[$id]->name])->all();
    }
}
