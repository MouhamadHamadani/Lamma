<?php

namespace App\Game;

use App\Enums\RoomStatus;
use App\Events\GameFinished;
use App\Events\GameStarted;
use App\Events\PlayerAnswered;
use App\Events\PlayerVoted;
use App\Events\QuestionRevealed;
use App\Events\QuestionStarted;
use App\Events\ScoreboardUpdated;
use App\Events\VoteExtended;
use App\Events\VoteResult;
use App\Events\VoteStarted;
use App\Jobs\AdvanceQuestion;
use App\Jobs\CloseVote;
use App\Jobs\RevealQuestion;
use App\Jobs\StartQuestion;
use App\Models\CategoryVote as CategoryVoteModel;
use App\Models\CategoryVoteRound;
use App\Models\PlayerAnswer;
use App\Models\QuestionOption;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\RoomQuestion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Runs a game, a state machine kept on the rooms and room_questions rows:
 *
 *   lobby -> playing (question n open) -> playing (question n revealed) -> ... -> finished
 *
 * The server owns the clock: a question is open until ends_at, answers are accepted until ends_at plus a short grace, and
 * the reveal only happens after that (or as soon as every connected player has answered). Every transition takes a lock,
 * re-reads the state inside it, and returns a Transition saying what it did, so a late job, a double click, an early
 * reveal or the host's "Next question" can never reveal a question twice or skip one.
 */
class GameEngine
{
    /** Answers still count this long after ends_at (a slow connection, a tap right at zero). */
    public const ANSWER_GRACE_MS = 500;

    /** How long the answer stays on screen before the next question. */
    public const REVEAL_SECONDS = 5;

    /** The 3 - 2 - 1 - Go! between "Start game" and question 1. */
    public const COUNTDOWN_SECONDS = 3;

    public function __construct(
        private readonly QuestionPicker $picker,
        private readonly ScoringService $scoring,
        private readonly SeenQuestions $seen,
        private readonly CategoryVote $votes,
    ) {}

    /**
     * Start the game. Rules: only the host, only from the lobby, at least one connected player, every connected player ready.
     * Picks the questions, fixes their option order and starts the countdown: question 1 opens when it ends (startQuestion, called
     * by the StartQuestion job and by the host screen's tick). A lock plus a row lock make a double click (or two hosts' tabs)
     * start it exactly once.
     *
     * @throws GameStartException
     */
    public function start(Room $room, User $by): Room
    {
        $lock = Cache::lock("room:{$room->id}:start", 10);
        if (! $lock->get()) {
            throw GameStartException::inProgress();
        }

        try {
            $started = DB::transaction(function () use ($room, $by) {
                $locked = Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();

                if ($locked->host_id !== $by->id) {
                    throw GameStartException::notHost();
                }
                if ($locked->status !== RoomStatus::Lobby) {
                    throw GameStartException::notInLobby();
                }

                $connected = $locked->players()->connected()->get();
                if ($connected->isEmpty()) {
                    throw GameStartException::noPlayers();
                }
                if ($connected->contains(fn ($player) => ! $player->is_ready)) {
                    throw GameStartException::notEveryoneReady();
                }

                if ($locked->isVote()) {
                    // Vote mode: only the first batch, from the first category. Each vote adds the next one (CategoryVote::close).
                    $batch = $this->votes->batchSize($locked, 0);
                    $drawn = $this->votes->drawBatch($locked, (int) $locked->first_category_id, $batch);
                    if ($drawn < $batch) {
                        throw GameStartException::notEnoughQuestions($drawn, $batch);
                    }
                } else {
                    $questions = $this->picker->pick($locked);
                    if ($questions->count() < $locked->settings->questionCount) {
                        throw GameStartException::notEnoughQuestions($questions->count(), $locked->settings->questionCount);
                    }

                    foreach ($questions as $index => $question) {
                        $locked->roomQuestions()->create([
                            'question_id' => $question->id,
                            'position' => $index + 1,
                            // Shuffled once, here: every screen shows the same A, B, C, D for the whole game.
                            'option_order' => $question->options->pluck('id')->shuffle()->values()->all(),
                        ]);
                    }
                }

                $now = now();
                $locked->update([
                    'status' => RoomStatus::Playing,
                    'started_at' => $now,
                    'countdown_ends_at' => $now->addSeconds(self::COUNTDOWN_SECONDS),
                ]);

                return $locked;
            });
        } finally {
            $lock->release();
        }

        GameStarted::broadcast($started)->toOthers();
        StartQuestion::dispatch($started->id)->delay($started->countdown_ends_at);

        return $started;
    }

    /**
     * Open question 1 once the countdown is over. Both the StartQuestion job and the host screen's tick ask for it, so whichever comes first
     * does it and the other finds it Already done; asking before the countdown ends is TooEarly. Later questions open through advance().
     * $force skips the wait (tests).
     */
    public function startQuestion(Room $room, bool $force = false): Transition
    {
        [$transition, $first, $fresh] = $this->locked($room, function (Room $fresh) use ($force) {
            $question = $fresh->status === RoomStatus::Playing ? $fresh->roomQuestions()->where('position', 1)->first() : null;
            if ($question === null) {
                return [Transition::Stale, null, $fresh];
            }
            if ($question->started_at !== null) {
                return [Transition::Already, null, $fresh];
            }
            if (! $force && now() < $this->countdownEndsAt($fresh)) {
                return [Transition::TooEarly, null, $fresh];
            }

            return [Transition::Done, $this->begin($fresh, 1), $fresh];
        });

        if ($transition === Transition::Done) {
            $this->announce($fresh, $first);
        }

        return $transition;
    }

    /** When the countdown ends and question 1 may open. A game started before countdowns existed has none: its first question is already open. */
    public function countdownEndsAt(Room $room): CarbonImmutable
    {
        return $room->countdown_ends_at ?? CarbonImmutable::instance($room->started_at ?? now());
    }

    /**
     * A player locks in an answer. Accepted only while the question is open and no later than ends_at plus the grace, once per
     * player (the unique index decides a race). Points are decided now (ScoringService) but added to the score at the reveal.
     *
     * @throws AnswerRejected
     */
    public function submitAnswer(RoomPlayer $player, int $optionId): PlayerAnswer
    {
        [$room, $question, $answer] = DB::transaction(function () use ($player, $optionId) {
            // Locking the room row serializes this with a reveal running at the same moment.
            $room = Room::query()->whereKey($player->room_id)->lockForUpdate()->firstOrFail();

            $question = $room->status === RoomStatus::Playing ? $room->currentQuestion() : null;
            if ($question === null || $question->revealed_at !== null) {
                throw new AnswerRejected(AnswerRejected::CLOSED);
            }
            if (now() > $question->ends_at->addMilliseconds(self::ANSWER_GRACE_MS)) {
                throw new AnswerRejected(AnswerRejected::LATE);
            }
            if (! in_array($optionId, $question->option_order ?? [], true)) {
                throw new AnswerRejected(AnswerRejected::INVALID_OPTION);
            }
            if ($question->answers()->where('room_player_id', $player->id)->exists()) {
                throw new AnswerRejected(AnswerRejected::ALREADY_ANSWERED);
            }

            $now = now();
            $correct = (bool) QuestionOption::query()->whereKey($optionId)->value('is_correct');
            $points = $this->scoring->pointsFor($correct, $now, $question->started_at, $question->ends_at, $this->scoring->isDoubled($room, $question));

            try {
                $answer = $question->answers()->create([
                    'room_player_id' => $player->id,
                    'question_option_id' => $optionId,
                    'answered_at' => $now,
                    'is_correct' => $correct,
                    'points' => $points->total,
                    'base_points' => $points->base,
                    'speed_bonus' => $points->speedBonus,
                    'multiplier' => $points->multiplier,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new AnswerRejected(AnswerRejected::ALREADY_ANSWERED);
            }

            return [$room, $question, $answer];
        });

        ['answered' => $answered, 'expected' => $expected] = $this->progress($room, $question);
        PlayerAnswered::broadcast($room, $player, $question->position, $answered, $expected)->toOthers();

        // Everyone connected has answered: no reason to wait for the timer.
        $this->reveal($room, $question->position);

        return $answer;
    }

    /**
     * Show the answer: set revealed_at, add the round's points to the scores, tell everyone, and schedule the next question.
     * Allowed once ends_at plus the grace has passed, or as soon as every connected player has answered; the host's "Skip timer"
     * passes $force to show it now.
     */
    public function reveal(Room $room, int $position, bool $force = false): Transition
    {
        [$transition, $question, $fresh] = $this->locked($room, function (Room $fresh) use ($position, $force) {
            $question = $fresh->status === RoomStatus::Playing ? $fresh->currentQuestion() : null;
            if ($question === null || $question->position !== $position) {
                return [Transition::Stale, null, $fresh];
            }
            if ($question->revealed_at !== null) {
                return [Transition::Already, null, $fresh];
            }
            if (! $force && now() < $this->revealDueAt($question) && ! $this->everyoneAnswered($fresh, $question)) {
                return [Transition::TooEarly, null, $fresh];
            }

            $question->update(['revealed_at' => now()]);
            $this->seen->record($fresh, $question->question_id);
            foreach ($question->answers()->where('points', '>', 0)->get() as $answer) {
                RoomPlayer::query()->whereKey($answer->room_player_id)->increment('score', $answer->points);
            }

            return [Transition::Done, $question->fresh(), $fresh];
        });

        if ($transition === Transition::Done) {
            QuestionRevealed::broadcast($fresh, $question)->toOthers();
            ScoreboardUpdated::broadcast($fresh, $question->position, $question)->toOthers();
            AdvanceQuestion::dispatch($fresh->id, $question->position)->delay(now()->addSeconds(self::REVEAL_SECONDS));
        }

        return $transition;
    }

    /**
     * Move on after a reveal: the next question, or the end of the game after the last. Automatic once the reveal pause is over;
     * the host's "Next question" passes $force to skip the wait. Asking for the same position twice does nothing the second time.
     *
     * Vote mode, at the end of a batch: the vote on the next category opens instead (Voting), and the next question waits for it to close and
     * its result to show (lamma.vote_result_seconds), which is when this is asked again (the AdvanceQuestion job that closeVote() queues, or the
     * host's tick). With only one category left to offer there is no vote: its batch is drawn and the next question opens at once.
     */
    public function advance(Room $room, int $position, bool $force = false): Transition
    {
        [$transition, $next, $fresh, $vote] = $this->locked($room, function (Room $fresh) use ($position, $force) {
            $question = $fresh->status === RoomStatus::Playing ? $fresh->currentQuestion() : null;
            if ($question === null || $question->position !== $position || $question->revealed_at === null) {
                return [Transition::Stale, null, $fresh, null];
            }

            $vote = $this->votes->roundAfter($fresh, $position);
            if ($vote?->isOpen()) {
                // The vote is under way; closeVote() moves the game on.
                return [Transition::Already, null, $fresh, null];
            }
            $waitUntil = $vote?->closed_at?->addSeconds(CategoryVote::resultSeconds()) ?? $question->revealed_at->addSeconds(self::REVEAL_SECONDS);
            if (! $force && now() < $waitUntil) {
                return [Transition::TooEarly, null, $fresh, null];
            }

            if ($position < $fresh->totalQuestions() && $vote === null && $this->votes->due($fresh, $position)) {
                $vote = $this->votes->open($fresh, $position);
                if ($vote->isOpen()) {
                    return [Transition::Voting, null, $fresh, $vote];
                }
            }

            // The last question, or (Vote mode, the content ran dry since the room was set up) nothing left to ask: the game is over.
            if ($position >= $fresh->totalQuestions() || ! $fresh->roomQuestions()->where('position', $position + 1)->exists()) {
                $fresh->update(['status' => RoomStatus::Finished, 'finished_at' => now()]);

                return [Transition::Finished, null, $fresh, null];
            }

            return [Transition::Done, $this->begin($fresh, $position + 1), $fresh, null];
        });

        match ($transition) {
            Transition::Finished => GameFinished::broadcast($fresh)->toOthers(),
            Transition::Done => $this->announce($fresh, $next),
            Transition::Voting => $this->announceVote($fresh, $vote),
            default => null,
        };

        return $transition;
    }

    /**
     * Vote mode: a player votes for one of the offered categories. Accepted only while the vote is open and no later than its deadline plus the same
     * grace as an answer, once per player per round (the unique index decides a race), never changed. The room row lock serializes it with
     * closeVote(), so a vote arriving as the vote closes is either counted or refused, never half-counted. When every connected player has voted
     * the vote closes at once.
     *
     * @throws AnswerRejected
     */
    public function castVote(RoomPlayer $player, int $categoryId): CategoryVoteModel
    {
        [$room, $round, $vote] = DB::transaction(function () use ($player, $categoryId) {
            $room = Room::query()->whereKey($player->room_id)->lockForUpdate()->firstOrFail();

            $round = $room->status === RoomStatus::Playing ? $this->votes->openRound($room) : null;
            if ($round === null) {
                throw new AnswerRejected(AnswerRejected::CLOSED);
            }
            if (now() > $this->votes->dueAt($round)) {
                throw new AnswerRejected(AnswerRejected::LATE);
            }
            if (! in_array($categoryId, $round->option_ids, true)) {
                throw new AnswerRejected(AnswerRejected::INVALID_OPTION);
            }
            if ($round->votes()->where('room_player_id', $player->id)->exists()) {
                throw new AnswerRejected(AnswerRejected::ALREADY_ANSWERED);
            }

            try {
                $vote = CategoryVoteModel::create(['room_id' => $room->id, 'round' => $round->round, 'room_player_id' => $player->id, 'category_id' => $categoryId]);
            } catch (UniqueConstraintViolationException) {
                throw new AnswerRejected(AnswerRejected::ALREADY_ANSWERED);
            }

            return [$room, $round, $vote];
        });

        ['voted' => $voted, 'expected' => $expected] = $this->votes->progress($room, $round);
        PlayerVoted::broadcast($room->code, $round->round, $this->votes->counts($round), $voted, $expected)->toOthers();

        // Everyone connected has voted: no reason to wait for the timer.
        $this->closeVote($room, $round->round);

        return $vote;
    }

    /**
     * Vote mode: close the vote. Allowed once its deadline plus the grace has passed, or as soon as every connected player has voted; the host's
     * "Skip vote" passes $force to close it now with the votes so far. Picks the winner (a tie or no votes: at random, and recorded), draws its
     * batch, shows the result, and queues the next question for when the result has been on screen long enough. Closing twice does nothing.
     * $deadlineMs is the deadline a CloseVote job was queued for: once the host's "+10 s" has moved it that job is Stale, and the new job takes over.
     */
    public function closeVote(Room $room, int $round, bool $force = false, ?int $deadlineMs = null): Transition
    {
        [$transition, $vote, $fresh] = $this->locked($room, function (Room $fresh) use ($round, $force, $deadlineMs) {
            $vote = $fresh->status === RoomStatus::Playing ? $fresh->voteRounds()->where('round', $round)->first() : null;
            if ($vote === null) {
                return [Transition::Stale, null, $fresh];
            }
            if (! $vote->isOpen()) {
                return [Transition::Already, null, $fresh];
            }
            if ($deadlineMs !== null && $vote->ends_at?->getTimestampMs() !== $deadlineMs) {
                return [Transition::Stale, null, $fresh];
            }
            if (! $force && now() < $this->votes->dueAt($vote) && ! $this->votes->everyoneVoted($fresh, $vote)) {
                return [Transition::TooEarly, null, $fresh];
            }

            return [Transition::Done, $this->votes->close($fresh, $vote)->fresh(), $fresh];
        });

        if ($transition === Transition::Done) {
            VoteResult::broadcast($fresh, $vote)->toOthers();
            AdvanceQuestion::dispatch($fresh->id, $this->votes->positionOf($fresh, $vote))->delay(now()->addSeconds(CategoryVote::resultSeconds()));
        }

        return $transition;
    }

    /**
     * Vote mode: the host's "+10 s". Moves the open vote's deadline, once per round, and queues a CloseVote for the new deadline (the one already queued finds
     * it moved and goes away). A second press is Already; a press after the vote closed, or for another round, is Stale. Neither changes anything.
     */
    public function extendVote(Room $room, int $round): Transition
    {
        [$transition, $vote, $fresh] = $this->locked($room, function (Room $fresh) use ($round) {
            $vote = $fresh->status === RoomStatus::Playing ? $fresh->voteRounds()->where('round', $round)->first() : null;
            if ($vote === null || ! $vote->isOpen() || $vote->ends_at === null) {
                return [Transition::Stale, null, $fresh];
            }
            if ($vote->extended_at !== null) {
                return [Transition::Already, null, $fresh];
            }

            $vote->update(['ends_at' => $vote->ends_at->addSeconds(CategoryVote::EXTEND_SECONDS), 'extended_at' => now()]);

            return [Transition::Done, $vote->fresh(), $fresh];
        });

        if ($transition === Transition::Done) {
            VoteExtended::broadcast($fresh, $vote)->toOthers();
            $this->queueCloseVote($fresh, $vote);
        }

        return $transition;
    }

    /**
     * Do whatever is due: open question 1 when the countdown is over, reveal an open question whose time is up, advance a revealed one
     * whose pause is over. The host screen calls this when its countdowns end, so the game keeps moving even if the queue worker is
     * slow or not running.
     */
    public function tick(Room $room): Transition
    {
        $room = $room->fresh() ?? $room;
        $question = $room->status === RoomStatus::Playing ? $room->currentQuestion() : null;

        $vote = $question?->revealed_at !== null ? $this->votes->openRound($room) : null;

        return match (true) {
            $question === null => $room->status === RoomStatus::Playing ? $this->startQuestion($room) : Transition::Stale,
            $question->revealed_at === null => $this->reveal($room, $question->position),
            $vote !== null => $this->closeVote($room, $vote->round),
            default => $this->advance($room, $question->position),
        };
    }

    /** When a question may be revealed on time alone: its deadline plus the answer grace. */
    public function revealDueAt(RoomQuestion $question): CarbonImmutable
    {
        return CarbonImmutable::instance($question->ends_at)->addMilliseconds(self::ANSWER_GRACE_MS);
    }

    /**
     * How many have answered, and how many are expected to: the connected players plus anyone who answered (and then dropped).
     *
     * @return array{answered: int, expected: int}
     */
    public function progress(Room $room, RoomQuestion $question): array
    {
        $answered = $question->answers()->pluck('room_player_id');
        $expected = $room->players()->where(fn ($query) => $query->whereNull('left_at')->orWhereIn('id', $answered))->count();

        return ['answered' => $answered->count(), 'expected' => $expected];
    }

    /** Every connected player has answered (and there is at least one). Disconnected players are not waited for. */
    private function everyoneAnswered(Room $room, RoomQuestion $question): bool
    {
        $connected = $room->players()->connected()->pluck('id');

        return $connected->isNotEmpty() && $connected->diff($question->answers()->pluck('room_player_id'))->isEmpty();
    }

    /** Open a question: set its start and deadline. No broadcasting here, so it can run inside a transaction. */
    private function begin(Room $room, int $position): RoomQuestion
    {
        $question = $room->roomQuestions()->where('position', $position)->firstOrFail();
        $started = now();
        $question->update(['started_at' => $started, 'ends_at' => $started->addSeconds($room->settings->secondsPerQuestion)]);

        return $question->fresh();
    }

    /** Tell everyone a question is open and schedule its reveal. Called after the transaction has committed. */
    private function announce(Room $room, RoomQuestion $question): void
    {
        QuestionStarted::broadcast($room, $question)->toOthers();
        RevealQuestion::dispatch($room->id, $question->position)->delay($this->revealDueAt($question));
    }

    /** Tell everyone the vote is open and schedule its close. Called after the transaction has committed. */
    private function announceVote(Room $room, CategoryVoteRound $vote): void
    {
        VoteStarted::broadcast($room, $vote)->toOthers();
        $this->queueCloseVote($room, $vote);
    }

    private function queueCloseVote(Room $room, CategoryVoteRound $vote): void
    {
        CloseVote::dispatch($room->id, $vote->round, $vote->ends_at?->getTimestampMs())->delay($this->votes->dueAt($vote));
    }

    /**
     * Run a transition under the room's lock and a row lock, on fresh data. Waits up to five seconds for a transition already in
     * progress, so a late job queues up behind it instead of failing.
     *
     * @template T
     *
     * @param  Closure(Room): T  $callback
     * @return T
     */
    private function locked(Room $room, Closure $callback): mixed
    {
        return Cache::lock("room:{$room->id}:game", 10)->block(5, fn () => DB::transaction(
            fn () => $callback(Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail()),
        ));
    }
}
