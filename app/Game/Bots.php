<?php

namespace App\Game;

use App\Jobs\BotAnswer;
use App\Models\BotPlan;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\RoomQuestion;
use Carbon\CarbonImmutable;

/**
 * The two players a demo room comes with, Sara (English) and Karim (Arabic). They are real room_players rows (is_bot), connected and ready from the start,
 * so every game rule treats them like anyone: they count for the "everyone answered" early reveal, score, and stand on the podium. What sets them apart
 * is only that they are never written to question_seen, never claimed by an account, and in no statistic (Room::completed(), GameStats, PlayerStats).
 *
 * A bot's answer is PLANNED when a question opens (plan()): a random moment between 25% and 85% of the question's time, right about 65% of the time (mt_rand, so a test can
 * seed it), stored in bot_plans. Two things carry the plan out, and either alone is enough (Phase 14.1): the delayed BotAnswer job queued with it, and GameEngine::tick(),
 * which the host screen calls every two seconds and which answers for every bot whose moment has passed (answerDue). So a demo works with the queue worker stopped. Both end in
 * answer(), which submits through the normal submitAnswer: the unique answer per player and the room lock make a job and a tick that meet answer once.
 */
class Bots
{
    /** @var list<array{nickname: string, locale: string}> */
    public const ROSTER = [
        ['nickname' => 'Sara', 'locale' => 'en'],
        ['nickname' => 'Karim', 'locale' => 'ar'],
    ];

    /** How often a bot gets a question right, in percent. */
    public const CORRECT_PERCENT = 65;

    /** The share of the question's time in which a bot answers. */
    public const EARLIEST = 0.25;

    public const LATEST = 0.85;

    /** Put the roster into a new demo room: connected and ready, with no token, no account and no photo. */
    public function seed(Room $room): void
    {
        foreach (self::ROSTER as $bot) {
            $room->players()->forceCreate([
                'room_id' => $room->id,
                'nickname' => $bot['nickname'],
                'locale' => $bot['locale'],
                'is_bot' => true,
                'score' => 0,
                'is_ready' => true,
                'joined_at' => now(),
                'left_at' => null,
            ]);
        }
    }

    /** A question just opened: plan one answer per bot (stored, and queued as a delayed job). Does nothing outside a demo room. */
    public function plan(Room $room, RoomQuestion $question): void
    {
        if (! $room->is_demo || $question->started_at === null || $question->ends_at === null) {
            return;
        }

        $duration = $question->started_at->diffInMilliseconds($question->ends_at, true);

        foreach ($room->players()->where('is_bot', true)->get() as $bot) {
            $share = (self::EARLIEST * 1000 + mt_rand(0, (int) round((self::LATEST - self::EARLIEST) * 1000))) / 1000;
            $correct = mt_rand(1, 100) <= self::CORRECT_PERCENT;

            $at = CarbonImmutable::instance($question->started_at)->addMilliseconds((int) round($duration * $share));

            BotPlan::query()->updateOrCreate(
                ['room_player_id' => $bot->id, 'position' => $question->position],
                ['room_id' => $room->id, 'answer_at' => $at, 'correct' => $correct, 'answered_at' => null],
            );
            BotAnswer::dispatch($room->id, $bot->id, $question->position, $correct)->delay($at);
        }
    }

    /**
     * What GameEngine::tick() does for a demo: answer for every bot of the open question whose planned moment has passed and who has not answered. It needs no queue worker.
     * An answer that completes the question reveals it (submitAnswer's early reveal), after which the rest are no-ops.
     */
    public function answerDue(GameEngine $engine, Room $room, RoomQuestion $question): void
    {
        if (! $room->is_demo || $question->revealed_at !== null) {
            return;
        }

        $due = BotPlan::query()
            ->where('room_id', $room->id)->where('position', $question->position)
            ->whereNull('answered_at')->where('answer_at', '<=', now()->format('Y-m-d H:i:s.v'))
            ->orderBy('answer_at')->orderBy('id')->get();

        foreach ($due as $plan) {
            $this->answer($engine, $room->id, $plan->room_player_id, $plan->position, $plan->correct);
        }
    }

    /** What the BotAnswer job does: answer the question that is open now, if it is the one the job was queued for. A late or repeated job does nothing. */
    public function answer(GameEngine $engine, int $roomId, int $playerId, int $position, bool $correct): void
    {
        $bot = RoomPlayer::query()->where('is_bot', true)->where('room_id', $roomId)->find($playerId);
        $question = $bot?->room->currentQuestion();
        if ($bot === null || $question === null || $question->position !== $position || $question->revealed_at !== null) {
            return;
        }

        try {
            $engine->submitAnswer($bot, $this->choose($question, $correct));
        } catch (AnswerRejected) {
            // Too late, or already answered (the job and the tick met): nothing to do.
        }

        // Answered, or no longer able to: neither the job nor the tick needs to come back to it.
        BotPlan::query()->where('room_player_id', $bot->id)->where('position', $position)->whereNull('answered_at')->update(['answered_at' => now()]);
    }

    /**
     * The option id to tap, or the number to type. "Right" for a number is inside the tolerance, "wrong" well outside it.
     */
    private function choose(RoomQuestion $roomQuestion, bool $correct): int|float
    {
        $question = $roomQuestion->question;

        if ($question->type->takesNumber()) {
            $tolerance = (float) $question->tolerance;
            $offset = $tolerance * ($correct ? mt_rand(0, 60) : mt_rand(130, 250)) / 100;

            return round((float) $question->answer_number + (mt_rand(0, 1) === 1 ? $offset : -$offset), 2);
        }

        $order = $roomQuestion->option_order ?? [];
        $right = (int) $question->options()->where('is_correct', true)->value('id');
        $wrong = array_values(array_diff($order, [$right]));

        return $correct || $wrong === [] ? $right : $wrong[array_rand($wrong)];
    }
}
