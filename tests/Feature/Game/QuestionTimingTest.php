<?php

use App\Enums\Difficulty;
use App\Events\GameFinished;
use App\Events\GameStarted;
use App\Events\PlayerAnswered;
use App\Events\QuestionRevealed;
use App\Events\QuestionStarted;
use App\Events\ScoreboardUpdated;
use App\Game\AnswerRejected;
use App\Game\GameEngine;
use App\Game\Transition;
use App\Jobs\AdvanceQuestion;
use App\Jobs\RevealQuestion;
use App\Livewire\Host\HostGame;
use App\Livewire\Player\PlayerGame;
use App\Models\Category;
use App\Models\Question;
use App\Models\Room;
use App\Models\RoomQuestion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
 * Phase 14.1, problems 3 and 4: a closest-number question is open 5 s longer (typing a number takes longer than a tap), and the answer stays on screen 8 s instead of 5 when
 * there is something to read (a number reveal, or a fun fact). Both numbers come from config and from ONE method each on GameEngine (secondsFor, revealSecondsFor).
 */

beforeEach(function () {
    Event::fake([GameStarted::class, QuestionStarted::class, PlayerAnswered::class, QuestionRevealed::class, ScoreboardUpdated::class, GameFinished::class]);
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00.000'));
});

/** A Classic game of these question types (the first one open at 12:00:00, 20 s per question), with or without fun facts. */
function timedGame(array $types, bool $facts = false, int $players = 2, array $settings = []): array
{
    $room = typedRoom($types, $players, ['secondsPerQuestion' => 20, 'doubleFinal' => false, ...$settings], withFact: $facts);
    $question = startGame($room);

    return [$room, $question, $room->players()->orderBy('id')->get()];
}

/** Everybody answers right: the question is revealed on the spot (12:00:00.000 when the clock is frozen there). */
function timedRevealAtOnce(Room $room, RoomQuestion $question, $players): RoomQuestion
{
    foreach ($players as $player) {
        answer($player, $question, true);
    }

    return $question->fresh();
}

describe('the settings', function () {
    it('ships 5 s more for a number question, 5 s of reveal and 8 s when there is something to read', function () {
        expect(config('lamma.number_question_extra_seconds'))->toBe(5)
            ->and(config('lamma.reveal_seconds'))->toBe(5)
            ->and(config('lamma.reveal_seconds_long'))->toBe(8);
    });
});

describe('the time a question is open', function () {
    it('gives a closest-number question the room\'s seconds plus the extra', function () {
        [$room, $question] = timedGame(['closest_number']);

        expect($question->started_at->format('H:i:s.v'))->toBe('12:00:00.000')
            ->and($question->ends_at->format('H:i:s.v'))->toBe('12:00:25.000')
            ->and($question->durationSeconds())->toBe(25)
            ->and(app(GameEngine::class)->secondsFor($room, $question))->toBe(25);
    });

    it('leaves multiple choice and true/false at the room\'s seconds', function (string $type) {
        [$room, $question] = timedGame([$type]);

        expect($question->ends_at->format('H:i:s.v'))->toBe('12:00:20.000')->and($question->durationSeconds())->toBe(20);
    })->with(['multiple_choice', 'true_false']);

    it('follows the room\'s time, whichever of 10, 15, 20 or 30', function (int $room, int $number) {
        [, $question] = timedGame(['closest_number'], settings: ['secondsPerQuestion' => $room]);

        expect($question->durationSeconds())->toBe($number);
    })->with([[10, 15], [15, 20], [20, 25], [30, 35]]);

    it('reads the extra from the config: another value, or none at all', function (int $extra, int $seconds) {
        config(['lamma.number_question_extra_seconds' => $extra]);

        [, $question] = timedGame(['closest_number']);

        expect($question->durationSeconds())->toBe($seconds);
    })->with([[0, 20], [10, 30], [-3, 20]]);

    it('tells every screen the longer time and the later deadline', function () {
        timedGame(['closest_number']);

        Event::assertDispatched(QuestionStarted::class, function (QuestionStarted $event) {
            $payload = $event->broadcastWith()['question'];

            return $payload['seconds'] === 25 && str_contains($payload['ends_at'], ':00:25') && ! array_key_exists('answer_number', $payload) && ! array_key_exists('tolerance', $payload);
        });
    });

    it('draws the host ring and the phone bar for the whole longer time', function () {
        [$room, , $players] = timedGame(['closest_number']);
        $players[0]->update(['guest_token' => 'timed'.str_repeat('x', 59), 'locale' => 'en']);

        Livewire::actingAs($room->fresh()->host)->test(HostGame::class, ['room' => $room->fresh()])->assertSeeHtml('data-test="timer"')->assertSeeHtml(', 25, ');
        Livewire::withCookie('lamma_guest', 'timed'.str_repeat('x', 59))->test(PlayerGame::class, ['room' => $room->fresh()])->assertSeeHtml(', 25, ');
    });

    it('draws a tap question for the room\'s time only', function () {
        [$room] = timedGame(['multiple_choice']);

        Livewire::actingAs($room->fresh()->host)->test(HostGame::class, ['room' => $room->fresh()])->assertSeeHtml(', 20, ')->assertDontSeeHtml(', 25, ');
    });
});

describe('the deadline of a number question', function () {
    it('queues the reveal for the new deadline plus the 500 ms grace', function () {
        [$room] = timedGame(['closest_number']);

        Queue::assertPushed(RevealQuestion::class, fn (RevealQuestion $job) => $job->roomId === $room->id && $job->position === 1 && $job->delay->format('H:i:s.v') === '12:00:25.500');
    });

    it('takes a guess right up to the deadline and a little after, and not past the grace', function () {
        [, , $players] = timedGame(['closest_number'], players: 4);

        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:20.600'));   // the old deadline is long gone: still open
        expect(timedTryGuess($players[0], 1969))->toBeNull();

        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:25.000'));   // the new deadline itself
        expect(timedTryGuess($players[1], 1969))->toBeNull();

        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:25.499'));   // inside the grace
        expect(timedTryGuess($players[2], 1969))->toBeNull();

        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:25.501'));
        expect(timedTryGuess($players[3], 1969))->toBe(AnswerRejected::LATE);
    });

    it('takes a guess on the very last millisecond of the grace', function () {
        [, , $players] = timedGame(['closest_number'], players: 2);

        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:25.500'));

        expect(timedTryGuess($players[0], 1969))->toBeNull();
    });

    it('is not revealed at the old deadline, but at the new one', function () {
        [$room, $question, $players] = timedGame(['closest_number'], players: 3);
        answer($players[0], $question, true);

        at('12:00:20.600');
        expect(app(GameEngine::class)->reveal($room, 1))->toBe(Transition::TooEarly);
        at('12:00:25.499');
        expect(app(GameEngine::class)->reveal($room, 1))->toBe(Transition::TooEarly);
        at('12:00:25.500');
        expect(app(GameEngine::class)->reveal($room, 1))->toBe(Transition::Done)->and($question->fresh()->revealed_at->format('H:i:s.v'))->toBe('12:00:25.500');
    });

    it('still reveals at once when everybody has answered, however much time is left', function () {
        [$room, $question, $players] = timedGame(['closest_number'], players: 3);

        $question = timedRevealAtOnce($room, $question, $players);

        expect($question->revealed_at)->not->toBeNull()->and($question->revealed_at->format('H:i:s.v'))->toBe('12:00:00.000');
    });

    it('scores a guess the same whenever in the longer time it is made: no speed bonus', function () {
        [, $question, $players] = timedGame(['closest_number'], players: 2);

        $early = guess($players[0], 1969);
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:24.900'));
        $late = guess($players[1], 1969);

        expect([$early->points, $early->speed_bonus])->toBe([100, 0])->and([$late->points, $late->speed_bonus])->toBe([100, 0]);
    });

    it('scores a tap answer by its own question\'s time, unchanged', function () {
        [$room, $question, $players] = timedGame(['multiple_choice', 'closest_number'], players: 2);

        $quick = answer($players[0], $question, true);   // at once: the full 100 + 100

        expect($quick->points)->toBe(200)->and($question->ends_at->format('H:i:s.v'))->toBe('12:00:20.000');
    });
});

/** The reason a guess is refused, or null when it was taken. */
function timedTryGuess($player, string|int|float $typed): ?string
{
    try {
        guess($player, $typed);
    } catch (AnswerRejected $rejected) {
        return $rejected->reason;
    }

    return null;
}

/** A top-level category with $count questions of one kind, all easy: a Vote batch of three draws exactly those. */
function timedCategory(string $kind, bool $fact = false, int $count = 3): Category
{
    $category = Category::factory()->create();
    $factory = Question::factory()->count($count)->for($category)->state(['difficulty' => Difficulty::Easy])->when($fact, fn ($f) => $f->withFact());
    ($kind === 'number' ? $factory->closestNumber(1969, 10) : $factory->withOptions())->create();

    return $category;
}

describe('a Vote game', function () {
    it('gives a number question the longer time in a batch, and the last question of the whole game too', function () {
        $room = voteRoom(players: 2, questions: 6, every: 3, categories: [timedCategory('number'), timedCategory('number'), timedCategory('number')], settings: ['secondsPerQuestion' => 20, 'doubleFinal' => true]);
        $engine = app(GameEngine::class);
        $first = startGame($room);
        expect($first->durationSeconds())->toBe(25);

        foreach ([1, 2, 3] as $position) {
            $question = $room->fresh()->currentQuestion();
            timedRevealAtOnce($room, $question, $room->players()->orderBy('id')->get());
            if ($position === 3) {
                $vote = $engine->advance($room, 3, force: true);
                expect($vote)->toBe(Transition::Voting);
                $round = votes()->openRound($room->fresh());
                $engine->closeVote($room, $round->round, force: true);
                $engine->advance($room, 3, force: true);
            } else {
                $engine->advance($room, $position, force: true);
            }
        }
        $fourth = $room->fresh()->currentQuestion();

        // The first question of the second batch, and the very last one of the game, are number questions open for 25 s.
        expect($fourth->position)->toBe(4)->and($fourth->durationSeconds())->toBe(25);
        foreach ([4, 5] as $position) {
            timedRevealAtOnce($room, $room->fresh()->currentQuestion(), $room->players()->orderBy('id')->get());
            $engine->advance($room, $position, force: true);
        }
        $last = $room->fresh()->currentQuestion();
        expect($last->position)->toBe(6)->and($last->durationSeconds())->toBe(25)
            ->and(app(GameEngine::class)->secondsFor($room->fresh(), $last))->toBe(25);
    });

    it('holds the reveal of a batch\'s last number question for 8 s before the vote opens, and 5 s for a tap question', function (string $kind, int $seconds) {
        $room = voteRoom(players: 2, questions: 6, every: 3, categories: [timedCategory($kind), timedCategory('tap'), timedCategory('tap')], settings: ['secondsPerQuestion' => 20]);
        $engine = app(GameEngine::class);
        startGame($room);
        foreach ([1, 2, 3] as $position) {
            timedRevealAtOnce($room, $room->fresh()->currentQuestion(), $room->players()->orderBy('id')->get());
            if ($position < 3) {
                $engine->advance($room, $position, force: true);
            }
        }

        // Revealed at 12:00:00.000; the vote opens `$seconds` later and not a millisecond before.
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00.000')->addSeconds($seconds)->subMillisecond());
        expect($engine->advance($room, 3))->toBe(Transition::TooEarly)->and(votes()->openRound($room->fresh()))->toBeNull();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00.000')->addSeconds($seconds));
        expect($engine->advance($room, 3))->toBe(Transition::Voting)->and(votes()->openRound($room->fresh()))->not->toBeNull();
    })->with([['number', 8], ['tap', 5]]);

    it('keeps the 3 s result beat after a vote whatever the question was', function () {
        $room = voteRoom(players: 2, questions: 6, every: 3, categories: [timedCategory('number'), timedCategory('tap'), timedCategory('tap')], settings: ['secondsPerQuestion' => 20]);
        $engine = app(GameEngine::class);
        startGame($room);
        foreach ([1, 2, 3] as $position) {
            timedRevealAtOnce($room, $room->fresh()->currentQuestion(), $room->players()->orderBy('id')->get());
            if ($position < 3) {
                $engine->advance($room, $position, force: true);
            }
        }
        at('12:00:08.000');
        $engine->advance($room, 3);
        $round = votes()->openRound($room->fresh());
        $engine->closeVote($room, $round->round, force: true);

        Queue::assertPushed(AdvanceQuestion::class, fn (AdvanceQuestion $job) => $job->position === 3 && $job->delay->format('H:i:s.v') === '12:00:11.000');
        at('12:00:10.999');
        expect($engine->advance($room, 3))->toBe(Transition::TooEarly);
        at('12:00:11.000');
        expect($engine->advance($room, 3))->toBe(Transition::Done);
    });
});

describe('how long the answer stays up', function () {
    it('is 5 s for a plain tap question and 8 s for a number question or any fun fact', function (string $type, bool $fact, int $seconds) {
        [$room, $question] = timedGame([$type], facts: $fact);

        expect(app(GameEngine::class)->revealSecondsFor($question))->toBe($seconds);
    })->with([
        'multiple choice' => ['multiple_choice', false, 5],
        'multiple choice with a fun fact' => ['multiple_choice', true, 8],
        'true/false' => ['true_false', false, 5],
        'true/false with a fun fact' => ['true_false', true, 8],
        'closest number' => ['closest_number', false, 8],
        'closest number with a fun fact' => ['closest_number', true, 8],
    ]);

    it('counts a fun fact written in one language only', function (array $fact) {
        [, $question] = timedGame(['multiple_choice']);
        $question->question->update(['explanation' => $fact]);

        expect(app(GameEngine::class)->revealSecondsFor($question->fresh()))->toBe(8);
    })->with([[['en' => 'Only English.']], [['ar' => 'بالعربية فقط.']]]);

    it('reads both lengths from the config', function () {
        config(['lamma.reveal_seconds' => 4, 'lamma.reveal_seconds_long' => 10]);
        [, $plain] = timedGame(['multiple_choice']);
        [, $number] = timedGame(['closest_number']);

        expect(app(GameEngine::class)->revealSecondsFor($plain))->toBe(4)->and(app(GameEngine::class)->revealSecondsFor($number))->toBe(10);
    });

    it('queues the next question for exactly that long after the reveal, and the job, the tick and the host\'s Next agree', function (string $type, bool $fact, int $seconds) {
        [$room, $question, $players] = timedGame([$type, 'multiple_choice'], facts: $fact);
        $engine = app(GameEngine::class);
        timedRevealAtOnce($room, $question, $players);   // revealed at 12:00:00.000

        Queue::assertPushed(AdvanceQuestion::class, fn (AdvanceQuestion $job) => $job->position === 1 && $job->delay->format('H:i:s.v') === sprintf('12:00:%02d.000', $seconds));

        // One millisecond early: the job, the tick and a late double click all find it is not time.
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00.000')->addSeconds($seconds)->subMillisecond());
        expect($engine->advance($room, 1))->toBe(Transition::TooEarly)->and($engine->tick($room))->toBe(Transition::TooEarly)
            ->and($room->fresh()->currentQuestion()->position)->toBe(1);

        // On time: the tick moves on, and the queued job a moment later is a harmless duplicate.
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00.000')->addSeconds($seconds));
        expect($engine->tick($room))->toBe(Transition::Done)->and($room->fresh()->currentQuestion()->position)->toBe(2)
            ->and($engine->advance($room, 1))->toBe(Transition::Stale);
    })->with([
        'a plain question: 5 s' => ['multiple_choice', false, 5],
        'a question with a fun fact: 8 s' => ['multiple_choice', true, 8],
        'a number question: 8 s' => ['closest_number', false, 8],
    ]);

    it('lets the host\'s Next question skip any of them', function () {
        [$room, $question, $players] = timedGame(['closest_number', 'multiple_choice']);
        timedRevealAtOnce($room, $question, $players);

        expect(app(GameEngine::class)->advance($room, 1, force: true))->toBe(Transition::Done)->and($room->fresh()->currentQuestion()->position)->toBe(2);
    });

    it('shows the host "Next question in" bar for the right length', function (string $type, int $seconds) {
        [$room, $question, $players] = timedGame([$type, 'multiple_choice']);
        timedRevealAtOnce($room, $question, $players);

        $html = Livewire::actingAs($room->fresh()->host)->test(HostGame::class, ['room' => $room->fresh()])->assertSee('Next question in')->html();

        preg_match('/lammaTimer\(\d+, \d+, (\d+), \'\'\)" data-test="next-countdown"/', $html, $bar);
        preg_match('/data-test="next-countdown".*?x-text="secs">(\d+)</s', $html, $shown);
        expect((int) $bar[1])->toBe($seconds)->and((int) $shown[1])->toBe($seconds);
    })->with([['multiple_choice', 5], ['closest_number', 8]]);
});
