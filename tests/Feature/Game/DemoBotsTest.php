<?php

use App\Events\GameStarted;
use App\Events\PlayerAnswered;
use App\Events\QuestionRevealed;
use App\Events\QuestionStarted;
use App\Events\ScoreboardUpdated;
use App\Game\Bots;
use App\Game\GameEngine;
use App\Jobs\BotAnswer;
use App\Models\PlayerAnswer;
use App\Models\Question;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/*
 * Sara and Karim answer through a delayed BotAnswer job, queued when a question opens: somewhere between 25% and 85% of its time, right about 65% of the time.
 * The clock is faked (at()) so "when" is exact, and the randomness is mt_rand, which a test can seed.
 */

beforeEach(function () {
    Queue::fake();
    Event::fake([GameStarted::class, QuestionStarted::class, PlayerAnswered::class, QuestionRevealed::class, ScoreboardUpdated::class]);
    at('12:00:00.000');
});

/** Every BotAnswer job pushed so far. */
function botJobs()
{
    return Queue::pushed(BotAnswer::class);
}

describe('planning', function () {
    it('queues one job per bot when a question opens, for a moment between 25% and 85% of its 20 seconds', function () {
        $room = demoGame(questions: 2);
        $question = startDemo($room);

        $jobs = botJobs();
        $bots = $room->players()->where('is_bot', true)->pluck('id')->all();

        expect($jobs)->toHaveCount(2)->and($jobs->pluck('playerId')->sort()->values()->all())->toBe(collect($bots)->sort()->values()->all())
            ->and($jobs->every(fn (BotAnswer $job) => $job->roomId === $room->id && $job->position === 1))->toBeTrue();

        $earliest = CarbonImmutable::parse('2026-10-01 12:00:05.000'); // 25% of 20 s
        $latest = CarbonImmutable::parse('2026-10-01 12:00:17.000'); // 85% of 20 s
        foreach ($jobs as $job) {
            expect($job->delay)->toBeInstanceOf(CarbonImmutable::class)
                ->and($job->delay->getTimestampMs())->toBeGreaterThanOrEqual($earliest->getTimestampMs())->toBeLessThanOrEqual($latest->getTimestampMs());
        }
    });

    it('spreads the answers over the whole window, never outside it', function () {
        $room = demoGame();
        $question = startDemo($room);
        Queue::fake(); // forget the first two
        mt_srand(2026);

        foreach (range(1, 300) as $n) {
            app(Bots::class)->plan($room->fresh(), $question);
        }

        $seconds = botJobs()->map(fn (BotAnswer $job) => $job->delay->getTimestampMs() - $question->started_at->getTimestampMs())->sort()->values();
        expect($seconds)->toHaveCount(600)->and($seconds->first())->toBeGreaterThanOrEqual(5000)->and($seconds->last())->toBeLessThanOrEqual(17000)
            ->and($seconds->first())->toBeLessThan(5600)->and($seconds->last())->toBeGreaterThan(16400);
    });

    it('is right about 65% of the time', function () {
        $room = demoGame();
        $question = startDemo($room);
        Queue::fake();
        mt_srand(14);

        foreach (range(1, 500) as $n) {
            app(Bots::class)->plan($room->fresh(), $question);
        }

        $rate = botJobs()->filter(fn (BotAnswer $job) => $job->correct)->count() / botJobs()->count();
        expect($rate)->toBeGreaterThan(0.6)->toBeLessThan(0.7);
    });

    it('plans for the next question too, and for no other kind of room', function () {
        $room = demoGame(questions: 2);
        startDemo($room);
        expect(botJobs())->toHaveCount(2);

        $real = gameRoom(players: 2, questions: 2);
        startGame($real);
        expect(botJobs())->toHaveCount(2); // still the demo's two
    });

    it('queues nothing in a room that has no bots', function () {
        $room = demoGame();
        $room->players()->where('is_bot', true)->delete();

        startDemo($room);

        expect(botJobs())->toHaveCount(0);
    });
});

describe('answering', function () {
    it('answers right when told to, wrong when told not to, once', function (bool $correct) {
        $room = demoGame();
        $question = startDemo($room);
        $bot = $room->players()->where('is_bot', true)->first();
        $this->travel(8)->seconds();

        (new BotAnswer($room->id, $bot->id, 1, $correct))->handle(app(Bots::class), app(GameEngine::class));

        $given = PlayerAnswer::sole();
        expect($given->room_player_id)->toBe($bot->id)->and($given->is_correct)->toBe($correct)->and($given->question_option_id === optionOf($question, true))->toBe($correct)
            ->and($question->option_order)->toContain($given->question_option_id);

        // A second run (a retry, a duplicate) changes nothing.
        (new BotAnswer($room->id, $bot->id, 1, ! $correct))->handle(app(Bots::class), app(GameEngine::class));
        expect(PlayerAnswer::count())->toBe(1)->and(PlayerAnswer::sole()->is_correct)->toBe($correct);
    })->with([true, false]);

    it('scores like a person: a speed bonus for an earlier answer, from the faked clock', function () {
        $room = demoGame();
        startDemo($room);
        [$sara, $karim] = $room->players()->where('is_bot', true)->orderBy('id')->get()->all();

        $this->travel(5)->seconds();
        (new BotAnswer($room->id, $sara->id, 1, true))->handle(app(Bots::class), app(GameEngine::class));
        $this->travel(10)->seconds();
        (new BotAnswer($room->id, $karim->id, 1, true))->handle(app(Bots::class), app(GameEngine::class));

        $early = PlayerAnswer::where('room_player_id', $sara->id)->first()->points;
        $late = PlayerAnswer::where('room_player_id', $karim->id)->first()->points;
        expect($early)->toBeGreaterThan($late)->and($late)->toBeGreaterThanOrEqual(100);
    });

    it('does nothing after the reveal, for another question, or for someone who is not a bot', function () {
        $room = demoGame(questions: 2);
        $first = startDemo($room);
        $bot = $room->players()->where('is_bot', true)->first();
        $human = $room->players()->where('is_bot', false)->first();

        // Not a bot: the job refuses to answer for a person.
        (new BotAnswer($room->id, $human->id, 1, true))->handle(app(Bots::class), app(GameEngine::class));
        // Another position than the one on screen.
        (new BotAnswer($room->id, $bot->id, 2, true))->handle(app(Bots::class), app(GameEngine::class));
        expect(PlayerAnswer::count())->toBe(0);

        // After the reveal.
        app(GameEngine::class)->reveal($room->fresh(), 1, force: true);
        (new BotAnswer($room->id, $bot->id, 1, true))->handle(app(Bots::class), app(GameEngine::class));
        expect(PlayerAnswer::count())->toBe(0);

        // A bot of a deleted room.
        (new BotAnswer(999999, $bot->id, 1, true))->handle(app(Bots::class), app(GameEngine::class));
        expect(PlayerAnswer::count())->toBe(0);
    });

    it('answers a true/false question with one of its two options', function () {
        $room = typedRoom(['true_false'], players: 1);
        $room->update(['is_demo' => true, 'host_id' => null, 'host_token_hash' => hash('sha256', DEMO_TOKEN)]);
        app(Bots::class)->seed($room);
        $question = startDemo($room->fresh());
        $bot = $room->players()->where('is_bot', true)->first();

        (new BotAnswer($room->id, $bot->id, 1, true))->handle(app(Bots::class), app(GameEngine::class));

        expect(PlayerAnswer::sole()->question_option_id)->toBe(optionOf($question, true))->and(PlayerAnswer::sole()->is_correct)->toBeTrue();
    });

    it('guesses a number inside the tolerance when it is right and far outside it when it is wrong', function () {
        $room = typedRoom(['closest_number'], players: 1, number: 1969, tolerance: 10);
        $room->update(['is_demo' => true, 'host_id' => null, 'host_token_hash' => hash('sha256', DEMO_TOKEN)]);
        app(Bots::class)->seed($room);
        startDemo($room->fresh());
        [$sara, $karim] = $room->players()->where('is_bot', true)->orderBy('id')->get()->all();

        mt_srand(5);
        (new BotAnswer($room->id, $sara->id, 1, true))->handle(app(Bots::class), app(GameEngine::class));
        (new BotAnswer($room->id, $karim->id, 1, false))->handle(app(Bots::class), app(GameEngine::class));

        $right = PlayerAnswer::where('room_player_id', $sara->id)->sole();
        $wrong = PlayerAnswer::where('room_player_id', $karim->id)->sole();
        expect(abs($right->number_value - 1969))->toBeLessThanOrEqual(6.0)->and($right->is_correct)->toBeTrue()->and($right->points)->toBeGreaterThan(0)
            ->and(abs($wrong->number_value - 1969))->toBeGreaterThanOrEqual(13.0)->and($wrong->is_correct)->toBeFalse()->and($wrong->points)->toBe(0);
    });
});

describe('the "everyone answered" rule', function () {
    it('counts the bots as connected players, so the reveal waits for them', function () {
        $room = demoGame();
        $question = startDemo($room);
        $human = $room->players()->where('is_bot', false)->first();

        $progress = app(GameEngine::class)->progress($room->fresh(), $question);
        expect($progress['expected'])->toBe(3);

        answer($human, $question);

        expect($question->fresh()->revealed_at)->toBeNull(); // the person answered; Sara and Karim have not
    });

    it('reveals at once when the person and both bots have answered', function () {
        $room = demoGame();
        $question = startDemo($room);
        $human = $room->players()->where('is_bot', false)->first();
        [$sara, $karim] = $room->players()->where('is_bot', true)->orderBy('id')->get()->all();

        answer($human, $question);
        (new BotAnswer($room->id, $sara->id, 1, true))->handle(app(Bots::class), app(GameEngine::class));
        expect($question->fresh()->revealed_at)->toBeNull();
        (new BotAnswer($room->id, $karim->id, 1, false))->handle(app(Bots::class), app(GameEngine::class));

        expect($question->fresh()->revealed_at)->not->toBeNull();
        // The bots scored like anyone and are on the scoreboard.
        expect($room->players()->where('is_bot', true)->sum('score'))->toBeGreaterThan(0);
    });

    it('still reveals on time when a bot never answers', function () {
        $room = demoGame();
        $question = startDemo($room);
        $human = $room->players()->where('is_bot', false)->first();
        answer($human, $question);

        $this->travel(21)->seconds();
        app(GameEngine::class)->tick($room->fresh());

        expect($question->fresh()->revealed_at)->not->toBeNull();
    });

    it('lets a whole demo be played to the end by one person and the bots', function () {
        $room = demoGame(questions: 3);
        $human = $room->players()->where('is_bot', false)->first();
        $engine = app(GameEngine::class);
        startDemo($room);

        foreach ([1, 2, 3] as $position) {
            $current = $room->fresh()->currentQuestion();
            expect($current->position)->toBe($position);
            answer($human, $current);
            foreach ($room->players()->where('is_bot', true)->get() as $bot) {
                (new BotAnswer($room->id, $bot->id, $position, true))->handle(app(Bots::class), $engine);
            }
            expect($current->fresh()->revealed_at)->not->toBeNull();
            $engine->advance($room->fresh(), $position, force: true);
        }

        expect($room->fresh()->isCompleted())->toBeTrue();
    });
});

it('has its own bots seeded only when asked', function () {
    $room = gameRoom(players: 1);

    expect($room->players()->where('is_bot', true)->count())->toBe(0);
    app(Bots::class)->seed($room);
    expect($room->players()->where('is_bot', true)->count())->toBe(2)->and(Bots::ROSTER)->toBe([['nickname' => 'Sara', 'locale' => 'en'], ['nickname' => 'Karim', 'locale' => 'ar']]);
});
