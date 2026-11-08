<?php

use App\Enums\HostScreenLocale;
use App\Events\GameFinished;
use App\Events\GameStarted;
use App\Events\PlayerAnswered;
use App\Events\QuestionRevealed;
use App\Events\QuestionStarted;
use App\Events\ScoreboardUpdated;
use App\Game\GameEngine;
use App\Game\ScoringService;
use App\Livewire\Host\HostGame;
use App\Livewire\Player\PlayerGame;
use App\Models\Room;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
 * Phase 14.1, problem 2: the reveal of the SECOND-TO-LAST question says "Next: double points" (a chip with the x2 sticker), on the big screen and on every phone, so nobody is
 * surprised by the last question counting double. It is there only when the host left the setting on and the game has at least two questions; in Classic and in Vote mode
 * (the last question of the whole game, not of a batch); in a demo too. The screens' live regions say it as well.
 */

const NEXT_TOKEN = 'nnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnn';

beforeEach(function () {
    Event::fake([GameStarted::class, QuestionStarted::class, PlayerAnswered::class, QuestionRevealed::class, ScoreboardUpdated::class, GameFinished::class]);
    Queue::fake();
    at('12:00:00.000');
});

/** A Classic game of $questions questions; the phone's player (first) speaks $locale. Question 1 is open. */
function nextGame(int $questions = 3, string $locale = 'en', array $settings = []): Room
{
    $room = gameRoom(players: 2, questions: $questions, settings: $settings);
    $room->players()->orderBy('id')->first()->update(['guest_token' => NEXT_TOKEN, 'locale' => $locale]);
    startGame($room);

    return $room;
}

/** Play on until question $position has been revealed (everybody answers right, the host skips the pauses); it stays on its reveal. */
function revealedUpTo(Room $room, int $position): void
{
    $engine = app(GameEngine::class);
    while (true) {
        $question = $room->fresh()->currentQuestion();
        if ($question->revealed_at === null) {
            foreach ($room->players()->orderBy('id')->get() as $player) {
                answer($player, $question, true);
            }
        }
        if ($question->position >= $position) {
            return;
        }
        $engine->advance($room, $question->position, force: true);
    }
}

function nextPhone(Room $room): string
{
    return Livewire::withCookie('lamma_guest', NEXT_TOKEN)->test(PlayerGame::class, ['room' => $room->fresh()])->html();
}

function nextHost(Room $room): string
{
    return Livewire::actingAs($room->host)->test(HostGame::class, ['room' => $room->fresh()])->html();
}

const NEXT_CHIP = 'data-test="double-next"';

describe('the rule', function () {
    it('is the second-to-last question, with the setting on and at least two questions', function (int $total, int $position, bool $setting, bool $expected) {
        $room = gameRoom(players: 1, questions: $total, settings: ['doubleFinal' => $setting]);
        startGame($room);
        $question = $room->roomQuestions()->where('position', $position)->firstOrFail();

        expect(app(ScoringService::class)->doublesNext($room->fresh(), $question))->toBe($expected);
    })->with([
        'second-to-last of three' => [3, 2, true, true],
        'first of three' => [3, 1, true, false],
        'the last of three' => [3, 3, true, false],
        'first of two' => [2, 1, true, true],
        'the last of two' => [2, 2, true, false],
        'the only question' => [1, 1, true, false],
        'the setting off' => [3, 2, false, false],
    ]);
});

describe('the phone', function () {
    it('shows the chip on the reveal of the second-to-last question and announces it', function () {
        $room = nextGame(3);
        revealedUpTo($room, 2);

        expect(nextPhone($room))->toContain(NEXT_CHIP)->toContain('Next: double points')->toMatch('/data-test="announcer">[^<]*Next: double points\s*</');
    });

    it('does not show it earlier, on the last question, or while the second-to-last is still being asked', function () {
        $room = nextGame(3);
        revealedUpTo($room, 1);
        expect(nextPhone($room))->not->toContain(NEXT_CHIP)->not->toContain('Next: double points');

        app(GameEngine::class)->advance($room, 1, force: true);   // question 2 is open, nobody has answered
        expect(nextPhone($room))->not->toContain(NEXT_CHIP);

        revealedUpTo($room, 3);   // the last question: x2 itself, not "next"
        $last = $room->fresh()->currentQuestion();
        expect($last->position)->toBe(3);
        expect(nextPhone($room))->not->toContain(NEXT_CHIP);
    });

    it('speaks Arabic to an Arabic phone', function () {
        $room = nextGame(3, 'ar');
        revealedUpTo($room, 2);

        expect(nextPhone($room))->toContain(NEXT_CHIP)->toContain('التالي: نقاط مضاعفة');
    });

    it('is there in a two-question game after question 1, and never in a one-question game', function () {
        $room = nextGame(2);
        revealedUpTo($room, 1);
        expect(nextPhone($room))->toContain(NEXT_CHIP);

        $room = nextGame(1);
        revealedUpTo($room, 1);
        expect(nextPhone($room))->not->toContain(NEXT_CHIP);
    });

    it('is absent when the host turned the double final off', function () {
        $room = nextGame(3, settings: ['doubleFinal' => false]);
        revealedUpTo($room, 2);

        expect(nextPhone($room))->not->toContain(NEXT_CHIP)->not->toContain('double points');
    });

    it('is on a wrong or timed-out reveal too: every phone is told', function () {
        $room = nextGame(3);
        $engine = app(GameEngine::class);
        $engine->reveal($room, 1, force: true);
        $engine->advance($room, 1, force: true);
        $engine->reveal($room, 2, force: true);   // nobody answered question 2: "time's up" for everybody

        expect(nextPhone($room))->toContain(NEXT_CHIP)->toContain('data-test="reveal-timeout"');
    });
});

describe('the big screen', function () {
    it('shows the chip on the reveal of the second-to-last question and announces it', function () {
        $room = nextGame(3);
        revealedUpTo($room, 2);

        expect(nextHost($room))->toContain(NEXT_CHIP)->toContain('Next: double points')->toMatch('/data-test="announcer">[^<]*Next: double points\s*</');
    });

    it('does not show it on any other reveal or on a question screen', function () {
        $room = nextGame(3);
        revealedUpTo($room, 1);
        expect(nextHost($room))->not->toContain(NEXT_CHIP);

        app(GameEngine::class)->advance($room, 1, force: true);
        expect(nextHost($room))->not->toContain(NEXT_CHIP);

        revealedUpTo($room, 3);
        expect(nextHost($room))->not->toContain(NEXT_CHIP)->toContain('data-test="double-chip"');
    });

    it('speaks the room\'s language: Arabic, English, or English in Both (like the rest of its labels; the x2 sticker needs no translation)', function () {
        $room = nextGame(3, settings: ['hostScreenLocale' => HostScreenLocale::Ar]);
        revealedUpTo($room, 2);
        expect(nextHost($room))->toContain(NEXT_CHIP)->toContain('التالي: نقاط مضاعفة')->not->toContain('Next: double points');

        $room = nextGame(3, settings: ['hostScreenLocale' => HostScreenLocale::En]);
        revealedUpTo($room, 2);
        expect(nextHost($room))->toContain('Next: double points')->not->toContain('التالي: نقاط مضاعفة');

        $room = nextGame(3, settings: ['hostScreenLocale' => HostScreenLocale::Both]);
        revealedUpTo($room, 2);
        expect(nextHost($room))->toContain('Next: double points')->not->toContain('التالي: نقاط مضاعفة');
    });

    it('is absent when the host turned the double final off, and in a one-question game', function () {
        $room = nextGame(3, settings: ['doubleFinal' => false]);
        revealedUpTo($room, 2);
        expect(nextHost($room))->not->toContain(NEXT_CHIP);

        $room = nextGame(1);
        revealedUpTo($room, 1);
        expect(nextHost($room))->not->toContain(NEXT_CHIP);
    });
});

describe('a Vote game', function () {
    it('counts the last question of the whole game, not of a batch', function () {
        // 6 questions in batches of 3: question 3 ends a batch (a vote follows) but is not second-to-last; question 5 is.
        $room = voteRoom(players: 2, questions: 6, every: 3, settings: ['secondsPerQuestion' => 20]);
        $room->players()->orderBy('id')->first()->update(['guest_token' => NEXT_TOKEN, 'locale' => 'en']);
        $engine = app(GameEngine::class);
        startGame($room);
        $players = $room->players()->orderBy('id')->get();

        $seen = [];
        for ($position = 1; $position <= 6; $position++) {
            $question = $room->fresh()->currentQuestion();
            foreach ($players as $player) {
                answer($player, $question, true);
            }
            $seen[$position] = [str_contains(nextPhone($room), NEXT_CHIP), str_contains(nextHost($room), NEXT_CHIP)];

            if ($position === 3) {
                $engine->advance($room, 3, force: true);   // opens the vote
                $engine->closeVote($room, votes()->openRound($room->fresh())->round, force: true);
            }
            if ($position < 6) {
                $engine->advance($room, $position, force: true);
            }
        }

        expect($seen)->toBe([1 => [false, false], 2 => [false, false], 3 => [false, false], 4 => [false, false], 5 => [true, true], 6 => [false, false]]);
    });

    it('shows it on the reveal that ends a batch when that is the second-to-last question', function () {
        // 4 questions in batches of 3: question 3 ends the first batch, the vote follows, and question 4 (the last) counts double.
        $room = voteRoom(players: 2, questions: 4, every: 3, settings: ['secondsPerQuestion' => 20]);
        $room->players()->orderBy('id')->first()->update(['guest_token' => NEXT_TOKEN, 'locale' => 'en']);
        startGame($room);
        $engine = app(GameEngine::class);
        $players = $room->players()->orderBy('id')->get();

        foreach ([1, 2, 3] as $position) {
            foreach ($players as $player) {
                answer($player, $room->fresh()->currentQuestion(), true);
            }
            if ($position < 3) {
                $engine->advance($room, $position, force: true);
            }
        }

        expect(nextPhone($room))->toContain(NEXT_CHIP);
        expect(nextHost($room))->toContain(NEXT_CHIP);
    });
});

describe('a demo', function () {
    it('shows it too: the demo has four questions with a double final', function () {
        demoContent();
        $room = demoGame(questions: 4);
        $room->players()->where('is_bot', false)->first()->update(['guest_token' => NEXT_TOKEN, 'locale' => 'en']);
        startDemo($room);
        $engine = app(GameEngine::class);

        foreach ([1, 2, 3] as $position) {
            $question = $room->fresh()->currentQuestion();
            foreach ($room->players()->orderBy('id')->get() as $player) {
                answer($player, $question, true);
            }
            if ($position < 3) {
                $engine->advance($room, $position, force: true);
            }
        }

        $host = demoHostPage(HostGame::class, $room->fresh())->html();
        $phone = Livewire::withCookie('lamma_guest', NEXT_TOKEN)->test(PlayerGame::class, ['room' => $room->fresh()])->html();

        expect($host)->toContain(NEXT_CHIP)->and($phone)->toContain(NEXT_CHIP);
    });
});

it('keeps the rule in ScoringService: the views only read what GameView decided', function () {
    $views = file_get_contents(resource_path('views/livewire/host/host-game.blade.php')).file_get_contents(resource_path('views/livewire/player/player-game.blade.php'));

    expect($views)->toContain('doublesNext')->not->toContain('totalQuestions()');
});
