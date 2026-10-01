<?php

namespace App\Game;

use App\Enums\RoomStatus;
use App\Events\GameFinished;
use App\Events\GameStarted;
use App\Events\PlayerAnswered;
use App\Events\QuestionRevealed;
use App\Events\QuestionStarted;
use App\Events\ScoreboardUpdated;
use App\Jobs\AdvanceQuestion;
use App\Jobs\RevealQuestion;
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

    public function __construct(
        private readonly QuestionPicker $picker,
        private readonly ScoringService $scoring,
    ) {}

    /**
     * Start the game. Rules: only the host, only from the lobby, at least one connected player, every connected player ready.
     * Picks the questions, fixes their option order and opens question 1. A lock plus a row lock make a double click (or two
     * hosts' tabs) start it exactly once.
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
            [$started, $first] = DB::transaction(function () use ($room, $by) {
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

                $locked->update(['status' => RoomStatus::Playing, 'started_at' => now()]);

                return [$locked, $this->begin($locked, 1)];
            });
        } finally {
            $lock->release();
        }

        GameStarted::broadcast($started)->toOthers();
        $this->announce($started, $first);

        return $started;
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

            try {
                $answer = $question->answers()->create([
                    'room_player_id' => $player->id,
                    'question_option_id' => $optionId,
                    'answered_at' => $now,
                    'is_correct' => $correct,
                    'points' => $this->scoring->pointsFor($correct, $now, $question),
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
     * Allowed once ends_at plus the grace has passed, or as soon as every connected player has answered.
     */
    public function reveal(Room $room, int $position): Transition
    {
        [$transition, $question, $fresh] = $this->locked($room, function (Room $fresh) use ($position) {
            $question = $fresh->status === RoomStatus::Playing ? $fresh->currentQuestion() : null;
            if ($question === null || $question->position !== $position) {
                return [Transition::Stale, null, $fresh];
            }
            if ($question->revealed_at !== null) {
                return [Transition::Already, null, $fresh];
            }
            if (now() < $this->revealDueAt($question) && ! $this->everyoneAnswered($fresh, $question)) {
                return [Transition::TooEarly, null, $fresh];
            }

            $question->update(['revealed_at' => now()]);
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
     */
    public function advance(Room $room, int $position, bool $force = false): Transition
    {
        [$transition, $next, $fresh] = $this->locked($room, function (Room $fresh) use ($position, $force) {
            $question = $fresh->status === RoomStatus::Playing ? $fresh->currentQuestion() : null;
            if ($question === null || $question->position !== $position || $question->revealed_at === null) {
                return [Transition::Stale, null, $fresh];
            }
            if (! $force && now() < $question->revealed_at->addSeconds(self::REVEAL_SECONDS)) {
                return [Transition::TooEarly, null, $fresh];
            }

            if ($position >= $fresh->roomQuestions()->count()) {
                $fresh->update(['status' => RoomStatus::Finished, 'finished_at' => now()]);

                return [Transition::Finished, null, $fresh];
            }

            return [Transition::Done, $this->begin($fresh, $position + 1), $fresh];
        });

        match ($transition) {
            Transition::Finished => GameFinished::broadcast($fresh)->toOthers(),
            Transition::Done => $this->announce($fresh, $next),
            default => null,
        };

        return $transition;
    }

    /**
     * Do whatever is due: reveal an open question whose time is up, advance a revealed one whose pause is over. The host screen
     * calls this when its countdowns end, so the game keeps moving even if the queue worker is slow or not running.
     */
    public function tick(Room $room): Transition
    {
        $room = $room->fresh() ?? $room;
        $question = $room->status === RoomStatus::Playing ? $room->currentQuestion() : null;

        return match (true) {
            $question === null => Transition::Stale,
            $question->revealed_at === null => $this->reveal($room, $question->position),
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
