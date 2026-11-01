<?php

use App\Events\GameFinished;
use App\Events\GameStarted;
use App\Events\PlayerAnswered;
use App\Events\QuestionRevealed;
use App\Events\QuestionStarted;
use App\Events\ScoreboardUpdated;
use App\Game\GameEngine;
use App\Livewire\Player\PlayerGame;
use App\Livewire\Player\PlayerLobby;
use App\Livewire\Player\PlayerResults;
use App\Models\Room;
use App\Models\RoomPlayer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * The phone's sounds, haptics and motion: the lobby (avatar pop, the Ready squish and teal burst), the countdown, the question (tiles slide in, a tap is
 * heard and felt, the chosen tile settles), the reveal (teal wipe and a self-drawing check, or a soft shake), streaks and the results. Display only.
 */

const MOTION_TOKEN = 'm0t1m0t1m0t1m0t1m0t1m0t1m0t1m0t1m0t1m0t1m0t1m0t1m0t1m0t1m0t1m0t1';

beforeEach(function () {
    Event::fake([GameStarted::class, QuestionStarted::class, PlayerAnswered::class, QuestionRevealed::class, ScoreboardUpdated::class, GameFinished::class]);
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00.000'));
});

function motionPhone(string $component, Room $room): Testable
{
    return Livewire::withCookie('lamma_guest', MOTION_TOKEN)->test($component, ['room' => $room]);
}

/** A room whose first player is the phone under test (English). */
function phoneRoom(int $players = 2, int $questions = 3, array $me = []): array
{
    $room = gameRoom(players: $players, questions: $questions);
    $room->players()->orderBy('id')->first()->update(['guest_token' => MOTION_TOKEN, 'locale' => 'en', ...$me]);

    return [$room, $room->players()->orderBy('id')->get()];
}

/** Plays one question per entry, the phone's player (first) answering right or wrong and the other the opposite, and stops on the last reveal. */
function streakGame(array $rights, array $me = []): array
{
    [$room, $players] = phoneRoom(questions: count($rights), me: $me);
    startGame($room);
    foreach ($rights as $i => $right) {
        $question = $room->fresh()->currentQuestion();
        answer($players[0], $question, $right);
        answer($players[1], $question, ! $right);
        if ($i < count($rights) - 1) {
            app(GameEngine::class)->advance($room, $i + 1, force: true);
        }
    }

    return [$room, $players[0]];
}

describe('the lobby', function () {
    it('pops the avatar in, whichever way it is drawn', function () {
        [$room] = phoneRoom();

        $html = motionPhone(PlayerLobby::class, $room)->html();

        expect(substr_count($html, 'motion-safe:animate-spring-in motion-reduce:animate-fade-in'))->toBeGreaterThanOrEqual(2);
    });

    it('squishes the Ready button and bursts teal confetti when it turns on, with the right sound and a tap haptic', function () {
        [$room] = phoneRoom(me: ['is_ready' => false]);

        $html = motionPhone(PlayerLobby::class, $room)->html();

        expect($html)->toContain("play(on ? 'ready-on' : 'ready-off')")->toContain("buzz('tap')")->toContain('lammaFx.squish($el)')->toContain("if (on) lammaConfetti.burst(\$el, 16, { tone: 'teal' })")
            ->toContain("const on = \$el.getAttribute('aria-pressed') !== 'true'")->toContain('aria-pressed="false" data-test="ready-button"');
    });

    it('keeps its pressed state and label in step with the server', function () {
        [$room] = phoneRoom(me: ['is_ready' => false]);
        $page = motionPhone(PlayerLobby::class, $room);

        $page->call('toggleReady')->assertSee("I'm ready!")->assertSeeHtml('aria-pressed="true"');
        $page->call('toggleReady')->assertDontSee("I'm ready!")->assertSeeHtml('aria-pressed="false"');
    });

    it('buzzes on the Join tap', function () {
        $html = file_get_contents(resource_path('views/livewire/player/join-room.blade.php'));

        expect($html)->toContain('x-on:click="$store.sound.buzz(\'tap\')"');
    });
});

describe('the countdown', function () {
    it('shows 3 · 2 · 1 · Go! on the phone as soon as the host starts, synced to the server\'s deadline', function () {
        [$room] = phoneRoom();
        app(GameEngine::class)->start($room, $room->host);

        $page = motionPhone(PlayerGame::class, $room)->assertSeeHtml('data-test="countdown"')->assertSeeHtml('data-n="3"')->assertSeeHtml('lammaCountdown(');
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:02.100'));
        expect(motionPhone(PlayerGame::class, $room)->html())->toContain('data-n="1"');
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:03.500'));
        expect(motionPhone(PlayerGame::class, $room)->html())->toContain('data-n="0"');
        expect($page->html())->toContain('wire:ignore x-data="lammaCountdown(');
    });

    it('is gone once the first question is open, and the switches stay above it', function () {
        [$room] = phoneRoom();
        app(GameEngine::class)->start($room, $room->host);
        expect(motionPhone(PlayerGame::class, $room)->html())->toContain('relative z-50 flex h-16')->toContain('data-test="sound-toggle"');

        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:03.100'));
        app(GameEngine::class)->tick($room);

        motionPhone(PlayerGame::class, $room)->assertDontSeeHtml('data-test="countdown"')->assertSeeHtml('data-test="answers"');
    });
});

describe('the question', function () {
    it('slides the answer tiles in one after the other, and plays and buzzes on a tap', function () {
        [$room] = phoneRoom();
        startGame($room);

        $html = motionPhone(PlayerGame::class, $room)->html();

        expect(substr_count($html, 'motion-safe:animate-slide-in motion-reduce:animate-fade-in'))->toBe(4)
            ->and($html)->toContain('animation-delay: 0ms')->toContain('animation-delay: 60ms')->toContain('animation-delay: 120ms')->toContain('animation-delay: 180ms')
            ->and(substr_count($html, "\$store.sound.play('lock-in'); \$store.sound.buzz('tap')"))->toBe(4);
    });

    it('lets the timer bar breathe under five seconds', function () {
        [$room] = phoneRoom();
        startGame($room);

        expect(motionPhone(PlayerGame::class, $room)->html())->toContain("'motion-safe:animate-bar-pulse': secs <= 5 && secs > 0");
    });

    it('settles the chosen tile into its tilt', function () {
        [$room, $players] = phoneRoom();
        $question = startGame($room);
        app(GameEngine::class)->submitAnswer($players[0], optionOf($question));

        motionPhone(PlayerGame::class, $room)->assertSeeHtml('data-test="locked-answer"')->assertSeeHtml('motion-safe:animate-settle motion-reduce:animate-fade-in');
    });
});

describe('the reveal', function () {
    it('wipes in teal, draws the check, pops +100, bursts confetti, and plays "correct" with a long buzz', function () {
        [$room, $me] = streakGame([true]);

        $html = motionPhone(PlayerGame::class, $room)->html();

        expect($html)->toContain('bg-teal motion-safe:animate-wipe-in motion-reduce:animate-fade-in')
            ->toContain('data-test="check-draw"')->toContain('pathLength="1"')->toContain('motion-safe:animate-draw')
            ->toContain('motion-safe:animate-chip-pop')->toContain("lammaConfetti.burst(\$el.querySelector('[data-test=points-gained]'), 22)")
            ->toContain("play('correct')")->toContain("buzz('correct')")->not->toContain('animate-shake');
    });

    it('stays cream for a wrong answer: a soft one-time shake of the cross, the "wrong" sound, a short buzz, no teal, no confetti', function () {
        [$room] = streakGame([false]);

        $html = motionPhone(PlayerGame::class, $room)->html();

        expect($html)->toContain('motion-safe:animate-shake')->toContain("play('wrong')")->toContain("buzz('wrong')")
            ->not->toContain('animate-wipe-in')->not->toContain('data-test="check-draw"')->not->toContain('lammaConfetti')->not->toContain('bg-red');
    });

    it('shakes the same way when time ran out', function () {
        [$room] = phoneRoom();
        startGame($room);
        app(GameEngine::class)->reveal($room, 1, force: true);

        expect(motionPhone(PlayerGame::class, $room)->html())->toContain('reveal-timeout')->toContain('motion-safe:animate-shake')->toContain("play('wrong')");
    });

    it('does one cue per answer even if a refresh runs the markup again', function () {
        [$room] = streakGame([true]);

        expect(motionPhone(PlayerGame::class, $room)->html())->toContain("\$store.sound.once('p1-result'");
    });

    it('announces the result once, in the handoff\'s words, and never describes the animations', function () {
        [$room] = streakGame([true]);

        $html = motionPhone(PlayerGame::class, $room)->html();

        expect($html)->toMatch('/data-test="announcer">\s*Correct, plus 100 points\s*</')
            ->and(substr_count($html, 'aria-live="polite"'))->toBeLessThanOrEqual(3);
    });
});

describe('streaks (display only)', function () {
    it('shows a bolt chip with the count from three right answers in a row', function () {
        [$room] = streakGame([true, true, true]);

        $page = motionPhone(PlayerGame::class, $room);

        $page->assertSeeHtml('data-test="streak"')->assertSeeHtml('data-streak="3"')->assertSee('3 in a row');
        expect($page->html())->toContain("play('streak')")->toMatch('/data-test="announcer">\s*Correct, plus 100 points, 3 in a row\s*</');
    });

    it('counts on: four in a row says four', function () {
        [$room] = streakGame([true, true, true, true]);

        motionPhone(PlayerGame::class, $room)->assertSeeHtml('data-streak="4"')->assertSee('4 in a row');
    });

    it('does not show for one or two right answers in a row, and the announcement stays plain', function (array $rights) {
        [$room] = streakGame($rights);

        $html = motionPhone(PlayerGame::class, $room)->html();

        expect($html)->not->toContain('data-test="streak"')->not->toContain("play('streak')")->toMatch('/data-test="announcer">\s*Correct, plus 100 points\s*</');
    })->with([
        'one' => [[true]],
        'two' => [[true, true]],
        'a wrong answer breaks it' => [[true, true, false, true]],
        'a wrong one in the middle' => [[true, false, true, true]],
    ]);

    it('is not shown on a wrong answer, however long the run was', function () {
        [$room] = streakGame([true, true, true, false]);

        motionPhone(PlayerGame::class, $room)->assertDontSeeHtml('data-test="streak"');
    });

    it('scores a flat 100 for every right answer, streak or not', function () {
        [$room, $me] = streakGame([true, true, true, true]);

        expect($me->fresh()->score)->toBe(400);
    });

    it('speaks Arabic to an Arabic player', function () {
        [$room] = streakGame([true, true, true], me: ['locale' => 'ar']);

        motionPhone(PlayerGame::class, $room)->assertSee('متتالية')->assertSeeHtml('data-streak="3"');
    });

    it('is in the room\'s numbers: the streak travels in the QuestionRevealed payload and never says which answer is right before the reveal', function () {
        [$room, $me] = streakGame([true, true, true]);

        $payload = Event::dispatched(QuestionRevealed::class)->last()[0]->broadcastWith();

        expect(collect($payload['points'])->firstWhere('player_id', $me->id)['streak'])->toBe(3);
        expect(collect($payload['points'])->firstWhere('player_id', RoomPlayer::query()->where('room_id', $room->id)->where('id', '!=', $me->id)->value('id'))['streak'])->toBe(0);
    });
});

describe('the results', function () {
    function myResultsPhone(int $myScore): Testable
    {
        $room = finishedGame(['Ali' => 300, 'Sara' => 200, 'Maya' => 100]);
        $room->players()->where('score', $myScore)->first()->update(['guest_token' => MOTION_TOKEN, 'locale' => 'en']);

        return motionPhone(PlayerResults::class, $room);
    }

    it('drops the rank tile in with a bounce, and slides the leaderboard in one row after another', function () {
        $html = myResultsPhone(300)->html();

        expect($html)->toContain('motion-safe:animate-tile-drop motion-reduce:animate-fade-in')
            ->and(substr_count($html, 'motion-safe:animate-slide-in'))->toBe(2)
            ->and($html)->toContain('animation-delay: 0ms')->toContain('animation-delay: 60ms')->toContain('animation-delay: 120ms');
    });

    it('pulses the player\'s own row once, and only theirs', function () {
        $html = myResultsPhone(200)->html();

        preg_match_all('/<li[^>]*data-you[^>]*>/s', $html, $own);

        expect(substr_count($html, 'motion-safe:animate-own-row'))->toBe(1)->and($own[0])->toHaveCount(1)->and($own[0][0])->toContain('motion-safe:animate-own-row');
    });

    it('gives first place the win fanfare, a long buzz and a confetti burst from the rank tile', function () {
        $html = myResultsPhone(300)->html();

        expect($html)->toContain("play('win')")->toContain("buzz('correct')")->toContain("lammaConfetti.burst(\$el.querySelector('[data-test=rank-tile]'), 36)")->toContain("once('results'");
    });

    it('is calmer for everyone else', function (int $score) {
        expect(myResultsPhone($score)->html())->not->toContain("play('win')")->not->toContain('lammaConfetti');
    })->with([200, 100]);

    it('does not cheer for a first place nobody earned', function () {
        $room = finishedGame(['Ali' => 0, 'Sara' => 0]);
        $room->players()->where('nickname', 'Ali')->update(['guest_token' => MOTION_TOKEN, 'locale' => 'en']);

        expect(motionPhone(PlayerResults::class, $room)->html())->not->toContain("play('win')");
    });
});

describe('streak chip', function () {
    it('isolates the number inside the sentence and shows a bolt', function () {
        $html = Blade::render('<x-lamma.streak-chip :count="5" />');

        expect($html)->toContain('data-streak="5"')->toContain('<bdi dir="ltr">5</bdi>')->toContain('M13 3L5 13.5h6L10 21l8-10.5h-6z')->toContain('bg-sun')->toContain('text-navy')->not->toContain('text-white');
    });
});
