<?php

use App\Enums\RoomStatus;
use App\Events\GameFinished;
use App\Events\GameStarted;
use App\Events\PlayerAnswered;
use App\Events\QuestionRevealed;
use App\Events\QuestionStarted;
use App\Events\ScoreboardUpdated;
use App\Game\Bots;
use App\Game\GameEngine;
use App\Game\Transition;
use App\Jobs\BotAnswer;
use App\Models\BotPlan;
use App\Models\PlayerAnswer;
use App\Models\Room;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/*
 * Phase 14.1, problem 5: a demo does not depend on the queue worker. When a question opens each bot's answer is PLANNED (bot_plans: when, and right or wrong) as well as queued as a
 * delayed BotAnswer job; GameEngine::tick(), which the host screen calls every two seconds, answers for the bots whose moment has passed. Queue::fake() below is "the worker is down":
 * no job ever runs, and the demo must still be playable to the end.
 */

beforeEach(function () {
    Queue::fake();
    Event::fake([GameStarted::class, QuestionStarted::class, PlayerAnswered::class, QuestionRevealed::class, ScoreboardUpdated::class, GameFinished::class]);
    at('12:00:00.000');
});

/** Fix the plans of question 1 so a test knows exactly when Sara (first) and Karim answer, and whether they are right. */
function planBots(Room $room, string $sara, string $karim, bool $saraRight = true, bool $karimRight = false): void
{
    [$saraRow, $karimRow] = $room->players()->where('is_bot', true)->orderBy('id')->get()->all();
    BotPlan::query()->where('room_player_id', $saraRow->id)->where('position', 1)->update(['answer_at' => "2026-10-01 {$sara}", 'correct' => $saraRight]);
    BotPlan::query()->where('room_player_id', $karimRow->id)->where('position', 1)->update(['answer_at' => "2026-10-01 {$karim}", 'correct' => $karimRight]);
}

function botAnswers(Room $room)
{
    return PlayerAnswer::query()->whereIn('room_player_id', $room->players()->where('is_bot', true)->pluck('id'))->get();
}

describe('the plan', function () {
    it('is stored when a question opens, one row per bot, with the moment the job is queued for and the same verdict', function () {
        $room = demoGame(questions: 2);
        startDemo($room);

        $plans = BotPlan::query()->where('room_id', $room->id)->orderBy('id')->get();
        $jobs = Queue::pushed(BotAnswer::class);

        expect($plans)->toHaveCount(2)->and($plans->pluck('position')->unique()->all())->toBe([1])
            ->and($plans->pluck('room_player_id')->sort()->values()->all())->toBe($room->players()->where('is_bot', true)->pluck('id')->sort()->values()->all());
        foreach ($plans as $plan) {
            $job = $jobs->firstWhere('playerId', $plan->room_player_id);
            expect($job->delay->getTimestampMs())->toBe($plan->answer_at->getTimestampMs())->and($job->correct)->toBe($plan->correct)
                ->and($plan->answer_at->getTimestampMs())->toBeGreaterThanOrEqual(5000 + now()->getTimestampMs() - 0)->and($plan->answered_at)->toBeNull();
        }
    });

    it('plans the next question too, and again for the same question without duplicating', function () {
        $room = demoGame(questions: 2);
        $question = startDemo($room);

        app(Bots::class)->plan($room->fresh(), $question);
        expect(BotPlan::count())->toBe(2);

        $engine = app(GameEngine::class);
        $engine->reveal($room->fresh(), 1, force: true);
        $engine->advance($room->fresh(), 1, force: true);
        expect(BotPlan::count())->toBe(4)->and(BotPlan::query()->where('position', 2)->count())->toBe(2);
    });

    it('is not made for a real room, and goes when the room does', function () {
        $real = gameRoom(players: 2, questions: 2);
        startGame($real);
        expect(BotPlan::count())->toBe(0);

        $room = demoGame();
        startDemo($room);
        expect(BotPlan::count())->toBe(2);
        $room->delete();
        expect(BotPlan::count())->toBe(0);
    });
});

describe('the tick answers for the bots', function () {
    it('does nothing before a bot\'s moment, answers for it at the moment and for the other at its own', function () {
        $room = demoGame();
        startDemo($room);
        planBots($room, '12:00:05.000', '12:00:09.000');
        $engine = app(GameEngine::class);
        [$sara, $karim] = $room->players()->where('is_bot', true)->orderBy('id')->get()->all();

        at('12:00:04.999');
        $engine->tick($room);
        expect(botAnswers($room))->toHaveCount(0);

        at('12:00:05.000');
        $engine->tick($room);
        expect(botAnswers($room))->toHaveCount(1)->and(botAnswers($room)->first()->room_player_id)->toBe($sara->id)->and(botAnswers($room)->first()->is_correct)->toBeTrue();

        at('12:00:08.999');
        $engine->tick($room);
        expect(botAnswers($room))->toHaveCount(1);

        at('12:00:09.000');
        $engine->tick($room);
        expect(botAnswers($room))->toHaveCount(2)->and(botAnswers($room)->firstWhere('room_player_id', $karim->id)->is_correct)->toBeFalse();
        expect(BotPlan::query()->whereNull('answered_at')->count())->toBe(0);
    });

    it('scores a bot like a person, from the clock of the tick: an earlier answer is worth more', function () {
        $room = demoGame();
        startDemo($room);
        planBots($room, '12:00:05.000', '12:00:12.000', true, true);
        $engine = app(GameEngine::class);

        at('12:00:05.000');
        $engine->tick($room);
        at('12:00:12.000');
        $engine->tick($room);

        [$early, $late] = botAnswers($room)->sortBy('answered_at')->values()->all();
        expect($early->points)->toBeGreaterThan($late->points)->and($late->points)->toBeGreaterThanOrEqual(100);
    });

    it('reveals the question when the bots\' answers complete it, and moves on after the pause', function () {
        $room = demoGame();
        $question = startDemo($room);
        planBots($room, '12:00:05.000', '12:00:09.000');
        answer($room->players()->where('is_bot', false)->first(), $question);
        $engine = app(GameEngine::class);

        at('12:00:09.000');
        $engine->tick($room);

        expect($question->fresh()->revealed_at)->not->toBeNull()->and($question->fresh()->revealed_at->format('H:i:s'))->toBe('12:00:09');
    });

    it('answers late but not too late: past the deadline and its grace the bot gets nothing and is let go', function () {
        $room = demoGame();
        $question = startDemo($room);
        planBots($room, '12:00:05.000', '12:00:09.000');
        $engine = app(GameEngine::class);

        at('12:00:20.501');   // the host screen was closed for a while: 15 s question + 500 ms grace is long over
        app(Bots::class)->answerDue($engine, $room->fresh(), $question->fresh());

        expect(botAnswers($room))->toHaveCount(0)->and(BotPlan::query()->whereNull('answered_at')->count())->toBe(0);
        expect($engine->tick($room))->toBe(Transition::Done)->and($question->fresh()->revealed_at)->not->toBeNull();
    });

    it('plays a whole demo to the end with the worker down: only the person and the host screen\'s tick', function () {
        $room = demoGame(questions: 3);
        $human = $room->players()->where('is_bot', false)->first();
        $engine = app(GameEngine::class);
        startDemo($room);

        foreach ([1, 2, 3] as $position) {
            $current = $room->fresh()->currentQuestion();
            expect($current->position)->toBe($position);
            answer($human, $current, $position !== 2);

            // The big screen polls every 2 s: the bots answer as their moments pass, the question reveals when all three have answered.
            for ($n = 0; $n < 40 && $room->fresh()->currentQuestion()->revealed_at === null; $n++) {
                $this->travel(2)->seconds();
                $engine->tick($room);
            }
            expect($room->fresh()->currentQuestion()->revealed_at)->not->toBeNull();
            expect(PlayerAnswer::query()->where('room_question_id', $current->id)->count())->toBe(3);

            // ...and after the pause the next question opens, or the game ends.
            for ($n = 0; $n < 20 && $room->fresh()->status === RoomStatus::Playing && $room->fresh()->currentQuestion()->position === $position; $n++) {
                $this->travel(2)->seconds();
                $engine->tick($room);
            }
        }

        expect($room->fresh()->status)->toBe(RoomStatus::Finished)->and($room->fresh()->isCompleted())->toBeTrue()
            ->and(PlayerAnswer::count())->toBe(9)->and(botAnswers($room))->toHaveCount(6)
            ->and(BotPlan::query()->whereNull('answered_at')->count())->toBe(0)->and(Queue::pushed(BotAnswer::class))->toHaveCount(6);
        Event::assertDispatchedTimes(GameFinished::class, 1);
    });
});

describe('the job and the tick meet', function () {
    it('answers once whichever comes first, in either order, and however often each runs', function (string $order) {
        $room = demoGame();
        startDemo($room);
        planBots($room, '12:00:05.000', '12:00:09.000');
        $engine = app(GameEngine::class);
        [$sara, $karim] = $room->players()->where('is_bot', true)->orderBy('id')->get()->all();
        at('12:00:09.000');

        $job = fn ($bot, bool $correct) => (new BotAnswer($room->id, $bot->id, 1, $correct))->handle(app(Bots::class), $engine);
        $tick = fn () => $engine->tick($room);

        match ($order) {
            'job, then tick' => [$job($sara, true), $job($karim, false), $tick(), $tick()],
            'tick, then job' => [$tick(), $job($sara, false), $job($karim, true), $tick()],
            'job and tick twice' => [$job($sara, true), $tick(), $job($sara, true), $tick(), $job($karim, false), $job($karim, false)],
        };

        expect(botAnswers($room))->toHaveCount(2)->and(botAnswers($room)->pluck('room_player_id')->unique())->toHaveCount(2)
            ->and(PlayerAnswer::count())->toBe(2)->and(BotPlan::query()->whereNull('answered_at')->count())->toBe(0);
    })->with(['job, then tick', 'tick, then job', 'job and tick twice']);

    it('keeps the verdict of the first one to answer', function () {
        $room = demoGame();
        startDemo($room);
        planBots($room, '12:00:05.000', '12:00:09.000', true, true);
        $engine = app(GameEngine::class);
        [$sara] = $room->players()->where('is_bot', true)->orderBy('id')->get()->all();
        at('12:00:09.000');

        $engine->tick($room);   // the plan says right
        (new BotAnswer($room->id, $sara->id, 1, false))->handle(app(Bots::class), $engine);   // a stale job that says wrong

        expect(PlayerAnswer::query()->where('room_player_id', $sara->id)->sole()->is_correct)->toBeTrue();
    });

    it('does not double-answer when the database refuses a second answer at the same moment', function () {
        $room = demoGame();
        $question = startDemo($room);
        planBots($room, '12:00:05.000', '12:00:09.000');
        [$sara] = $room->players()->where('is_bot', true)->orderBy('id')->get()->all();
        at('12:00:09.000');
        $engine = app(GameEngine::class);

        // The tick and the job both found the plan unanswered (each read it before the other wrote): run the answer twice without the plan's answered_at in the way.
        app(Bots::class)->answer($engine, $room->id, $sara->id, 1, true);
        BotPlan::query()->update(['answered_at' => null]);
        app(Bots::class)->answer($engine, $room->id, $sara->id, 1, true);

        expect(PlayerAnswer::query()->where('room_player_id', $sara->id)->count())->toBe(1);
    });
});

describe('what a demo and a bot must still never reach', function () {
    it('never answers for a person or in a room that is not a demo, even with a plan row', function () {
        $real = gameRoom(players: 2, questions: 2);
        $question = startGame($real);
        $someone = $real->players()->orderBy('id')->first();
        $someone->update(['is_bot' => true]);   // not a demo: nothing may be planned or answered for it
        BotPlan::create(['room_id' => $real->id, 'room_player_id' => $someone->id, 'position' => 1, 'answer_at' => '2026-10-01 12:00:01.000', 'correct' => true]);
        at('12:00:10.000');

        app(GameEngine::class)->tick($real);

        expect(PlayerAnswer::count())->toBe(0);

        $demo = demoGame();
        startDemo($demo);
        $human = $demo->players()->where('is_bot', false)->first();
        BotPlan::create(['room_id' => $demo->id, 'room_player_id' => $human->id, 'position' => 1, 'answer_at' => '2026-10-01 12:00:01.000', 'correct' => true]);
        at('12:00:10.000');
        app(GameEngine::class)->tick($demo);

        expect(PlayerAnswer::query()->where('room_player_id', $human->id)->count())->toBe(0);
    });

    it('does not answer for another question, or for a question that has been revealed', function () {
        $room = demoGame(questions: 2);
        $question = startDemo($room);
        planBots($room, '12:00:05.000', '12:00:09.000');
        BotPlan::query()->where('room_id', $room->id)->orderBy('id')->first()->update(['position' => 2]);
        $engine = app(GameEngine::class);

        at('12:00:10.000');
        $engine->tick($room);
        expect(botAnswers($room))->toHaveCount(1);   // only the plan for position 1

        $engine->reveal($room->fresh(), 1, force: true);
        BotPlan::query()->update(['answered_at' => null, 'position' => 1]);
        app(Bots::class)->answerDue($engine, $room->fresh(), $question->fresh());
        expect(botAnswers($room))->toHaveCount(1);
    });

    it('writes nothing to question_seen for a bot, and keeps the demo out of the statistics', function () {
        $room = demoGame(questions: 2);
        $human = $room->players()->where('is_bot', false)->first();
        $human->update(['guest_token' => DEMO_GUEST]);
        $question = startDemo($room);
        planBots($room, '12:00:03.000', '12:00:04.000');
        answer($human, $question);
        $engine = app(GameEngine::class);

        at('12:00:04.000');
        $engine->tick($room);

        expect($question->fresh()->revealed_at)->not->toBeNull()
            ->and(DB::table('question_seen')->count())->toBe(1)->and(DB::table('question_seen')->value('guest_token'))->toBe(DEMO_GUEST)
            ->and(Room::real()->whereKey($room->id)->exists())->toBeFalse();
    });
});
