<?php

use App\Enums\RoomStatus;
use App\Events\GameFinished;
use App\Events\GameStarted;
use App\Events\PlayerAnswered;
use App\Events\QuestionRevealed;
use App\Events\QuestionStarted;
use App\Events\ScoreboardUpdated;
use App\Game\GameEngine;
use App\Livewire\Player\PlayerGame;
use App\Livewire\Player\PlayerLobby;
use App\Models\QuestionOption;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\RoomQuestion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

const GAME_TOKEN = 'g4m3g4m3g4m3g4m3g4m3g4m3g4m3g4m3g4m3g4m3g4m3g4m3g4m3g4m3g4m3g4m3';

beforeEach(function () {
    Event::fake([GameStarted::class, QuestionStarted::class, PlayerAnswered::class, QuestionRevealed::class, ScoreboardUpdated::class, GameFinished::class]);
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00.000'));
});

/**
 * A started game with three connected players; the first is the phone under test. Question 1 is open from 12:00:00 to 12:00:20.
 *
 * @return array{0: Room, 1: RoomQuestion, 2: Collection<int, RoomPlayer>, 3: RoomPlayer}
 */
function phoneGame(array $me = [], int $questions = 3): array
{
    $room = gameRoom(players: 3, questions: $questions);
    $room->players()->orderBy('id')->first()->update(['guest_token' => GAME_TOKEN, 'locale' => 'en', ...$me]);
    $question = startGame($room);
    $players = $room->players()->orderBy('id')->get();

    return [$room, $question, $players, $players[0]];
}

function phone(Room $room): Testable
{
    return Livewire::withCookie('lamma_guest', GAME_TOKEN)->test(PlayerGame::class, ['room' => $room]);
}

/** Show the answer of the open question, now. */
function revealNow(Room $room, int $position = 1): void
{
    app(GameEngine::class)->reveal($room, $position, force: true);
}

function option(RoomQuestion $question, int $index): QuestionOption
{
    return $question->question->options->firstWhere('id', $question->option_order[$index]);
}

describe('the question screen (player-3)', function () {
    it('shows the question and answers in the player\'s own language, and nothing in the other', function () {
        [$room, $question] = phoneGame(['locale' => 'en']);
        $first = option($question, 0);

        phone($room)->assertSee($question->question->getTranslation('text', 'en'))->assertSee($first->getTranslation('text', 'en'))
            ->assertDontSee($question->question->getTranslation('text', 'ar'))->assertDontSee($first->getTranslation('text', 'ar'))
            ->assertSee("Tap one answer. You can't change it after.");

        [$room, $question] = phoneGame(['locale' => 'ar']);
        $first = option($question, 0);

        phone($room)->assertSee($question->question->getTranslation('text', 'ar'))->assertSee($first->getTranslation('text', 'ar'))
            ->assertDontSee($question->question->getTranslation('text', 'en'))->assertSee('اضغط على إجابة واحدة');
    });

    it('stacks the four answers in the stored order as tap targets, each with its shape', function () {
        [$room, $question] = phoneGame();
        $texts = collect(range(0, 3))->map(fn ($i) => option($question, $i)->getTranslation('text', 'en'))->all();

        $page = phone($room)->assertSeeInOrder($texts);

        foreach (range(0, 3) as $i) {
            $page->assertSeeHtml('wire:click="answer('.option($question, $i)->id.')"');
        }
        expect(substr_count($page->html(), 'viewBox="0 0 18 18"'))->toBeGreaterThanOrEqual(4);
    });

    it('shows the question number, the score and a timer built from the server deadline', function () {
        [$room, $question, , $me] = phoneGame();
        $me->update(['score' => 200]);

        phone($room)->assertSee('Q 1 / 3')->assertSee('200 pts')->assertSeeHtml('role="timer"')
            ->assertSeeHtml('lammaTimer('.$question->ends_at->getTimestampMs().', '.now()->getTimestampMs().', 20,');
    });

    it('never says which answer is correct before the reveal', function () {
        [$room, $question] = phoneGame();

        $page = phone($room);

        $page->assertDontSeeHtml('is_correct')->assertDontSeeHtml('data-correct')->assertDontSee('Correct');
    });

    it('tells screen readers about the question in a polite live region, and the countdown only at 10 s and 5 s', function () {
        [$room] = phoneGame();

        $page = phone($room);

        $page->assertSeeHtml('role="status" aria-live="polite" aria-atomic="true" data-test="announcer"')->assertSee('Question 1 of 3');
        // the timer's own region is empty until lammaTimer fills it at 10 s and 5 s (resources/js/lamma.js), from this translated template
        expect($page->html())->toContain(':seconds seconds left')->and(substr_count($page->html(), 'x-text="say"'))->toBe(1);
    });
});

describe('answering (player-4)', function () {
    it('locks the answer with one tap: no confirm step', function () {
        [$room, $question, $players, $me] = phoneGame();
        $chosen = option($question, 1);

        $page = phone($room)->call('answer', $chosen->id);

        expect($question->answers()->where('room_player_id', $me->id)->value('question_option_id'))->toBe($chosen->id);
        $page->assertSee('Answer locked in')->assertSee('Waiting for the others…')->assertSee($chosen->getTranslation('text', 'en'))
            ->assertSeeHtml('data-test="locked-answer"')->assertDontSeeHtml('data-test="answers"')->assertSee('1 of 3 answered');
        Event::assertDispatched(PlayerAnswered::class, fn (PlayerAnswered $e) => $e->player['id'] === $me->id && $e->answered === 1);
    });

    it('announces that the answer is locked in', function () {
        [$room, $question] = phoneGame();

        $page = phone($room)->call('answer', option($question, 0)->id);

        expect($page->html())->toMatch('/data-test="announcer">\s*Answer locked in\s*</');
    });

    it('keeps the first answer when a second tap arrives', function () {
        [$room, $question, , $me] = phoneGame();
        $first = option($question, 0);
        $second = option($question, 2);

        phone($room)->call('answer', $first->id)->call('answer', $second->id);

        expect($question->answers()->where('room_player_id', $me->id)->count())->toBe(1)->and($question->answers()->value('question_option_id'))->toBe($first->id);
    });

    it('accepts an answer in the grace after the timer and refuses one after that', function () {
        [$room, $question, , $me] = phoneGame();
        $page = phone($room);

        at('12:00:20.500');
        $page->call('answer', option($question, 0)->id);
        expect($question->answers()->count())->toBe(1);

        $question->answers()->delete();
        at('12:00:20.501');
        $page->call('answer', option($question, 0)->id);
        expect($question->answers()->count())->toBe(0);
    });

    it('refuses an option that is not on this question, quietly', function () {
        [$room, $question] = phoneGame();
        $foreign = RoomQuestion::where('room_id', $room->id)->where('position', 2)->first()->question->options->first();

        phone($room)->call('answer', $foreign->id)->assertOk()->assertSee("Tap one answer. You can't change it after.");

        expect($question->answers()->count())->toBe(0);
    });

    it('refuses an answer once the question is revealed', function () {
        [$room, $question] = phoneGame();
        $page = phone($room);
        revealNow($room);

        $page->call('answer', option($question, 0)->id);

        expect($question->answers()->count())->toBe(0);
    });

    it('shows the answered count, leaving out players who are not connected', function () {
        [$room, $question, $players] = phoneGame();
        $players[2]->update(['left_at' => now()]);

        phone($room)->call('answer', option($question, 0)->id)->assertSee('Waiting for the others…')->assertSee('1 of 2 answered');
    });

    it('is for participants only', function () {
        [$room] = phoneGame();

        Livewire::test(PlayerGame::class, ['room' => $room])->assertForbidden();
    });
});

describe('the reveal screens (player-5)', function () {
    it('shows Correct with the points, the rank and the score on teal', function () {
        [$room, $question, $players, $me] = phoneGame();
        app(GameEngine::class)->submitAnswer($me, optionOf($question));
        revealNow($room);

        $page = phone($room);

        $page->assertSee('Correct!')->assertSeeHtml('data-test="reveal-correct"')->assertSeeHtml('lammaCountUp(100)')
            ->assertSee('Correct answer')->assertSee(option($question, array_search(optionOf($question), $question->option_order))->getTranslation('text', 'en'))
            ->assertSee('Next question coming up…');
        expect($page->html())->toContain('class="relative min-h-dvh bg-teal"')->and($page->html())->toContain("You're")->and($page->html())->toContain('1st');
        $page->assertSeeHtml('Correct, plus 100 points');
    });

    it('shows Not quite on cream when the answer was wrong, with the correct one', function () {
        [$room, $question, $players, $me] = phoneGame();
        app(GameEngine::class)->submitAnswer($players[1], optionOf($question));
        app(GameEngine::class)->submitAnswer($me, optionOf($question, false));
        revealNow($room);

        $page = phone($room);

        $page->assertSee('Not quite!')->assertSeeHtml('data-test="reveal-wrong"')->assertSeeHtml('lammaCountUp(0)')->assertSee('Correct answer')
            ->assertSee(option($question, array_search(optionOf($question), $question->option_order))->getTranslation('text', 'en'));
        expect($page->html())->not->toContain('relative min-h-dvh bg-teal')->and($page->html())->toContain('2nd');
    });

    it('shows Time\'s up when nothing was answered, with the correct answer', function () {
        [$room, $question] = phoneGame();
        revealNow($room);

        phone($room)->assertSee("Time's up!")->assertSeeHtml('data-test="reveal-timeout"')->assertSee('Correct answer')->assertSee('Time\'s up, the answer was');
    });

    it('speaks Arabic to an Arabic player', function () {
        [$room, $question, , $me] = phoneGame(['locale' => 'ar']);
        revealNow($room);
        phone($room)->assertSee('انتهى الوقت!')->assertSee('الإجابة الصحيحة');

        [$room, $question, $players, $me] = phoneGame(['locale' => 'ar']);
        app(GameEngine::class)->submitAnswer($me, optionOf($question, false));
        revealNow($room);
        phone($room)->assertSee('ليس هذه المرة!');

        [$room, $question, $players, $me] = phoneGame(['locale' => 'ar']);
        app(GameEngine::class)->submitAnswer($me, optionOf($question));
        revealNow($room);
        phone($room)->assertSee('صحيح!')->assertSee('أنت في المركز')->assertSee('نقطة');
    });

    it('keeps scores, ranks and the plus-points left to right', function () {
        [$room, $question, , $me] = phoneGame(['locale' => 'ar']);
        app(GameEngine::class)->submitAnswer($me, optionOf($question));
        revealNow($room);

        $page = phone($room);

        $page->assertSeeHtml('dir="ltr" x-data="lammaCountUp(100)"');
        expect($page->html())->toContain('<bdi dir="ltr">100</bdi>');
    });

    it('says the final results are coming up after the last question', function () {
        [$room, , , $me] = phoneGame(questions: 1);
        revealNow($room);

        phone($room)->assertSee('Final results coming up…')->assertDontSee('Next question coming up…');
    });

    it('announces the result for screen readers', function () {
        [$room, $question, , $me] = phoneGame();
        app(GameEngine::class)->submitAnswer($me, optionOf($question, false));
        revealNow($room);

        phone($room)->assertSeeInOrder(['data-test="announcer"', 'Not quite, the answer was']);
    });
});

describe('the end of the game', function () {
    it('hands over to the results screen: the final place, the leaderboard and a way home', function () {
        [$room, $question, $players, $me] = phoneGame(questions: 1);
        app(GameEngine::class)->submitAnswer($me, optionOf($question));
        revealNow($room);
        app(GameEngine::class)->advance($room, 1, force: true);

        $page = phone($room)->assertSeeHtml('data-phase="over"')->assertSeeHtml('data-test="player-results"')->assertSee('Game over')->assertSee('Leave room');

        expect($page->html())->toContain('data-rank="1"')->and($page->html())->toContain('You won, ');
    });

    it('says the room is closed when the host closed it part-way', function () {
        [$room] = phoneGame();
        $room->update(['status' => RoomStatus::Finished]);

        phone($room)->assertSee('This room has been closed.')->assertSeeHtml('data-test="closed"')->assertDontSeeHtml('data-test="game-over"');
    });
});

describe('after a reconnect or a reload', function () {
    it('lands on the open question with the time left from ends_at', function () {
        [$room, $question] = phoneGame();
        at('12:00:13.000');

        phone($room)->assertSee($question->question->getTranslation('text', 'en'))->assertSee('Question 1 of 3')
            ->assertSeeHtml('lammaTimer('.$question->ends_at->getTimestampMs().', '.now()->getTimestampMs().', 20,');
    });

    it('remembers the answer given before the drop', function () {
        [$room, $question, , $me] = phoneGame();
        $chosen = option($question, 3);
        app(GameEngine::class)->submitAnswer($me, $chosen->id);
        at('12:00:09.000');

        phone($room)->assertSee('Answer locked in')->assertSee($chosen->getTranslation('text', 'en'))->assertDontSeeHtml('data-test="answers"');
    });

    it('can still answer after coming back mid-question, while there is time', function () {
        [$room, $question, , $me] = phoneGame();
        $me->update(['left_at' => now()]); // dropped
        at('12:00:15.000');
        $me->update(['left_at' => null]); // back

        phone($room)->call('answer', option($question, 0)->id)->assertSee('Answer locked in');

        expect($question->answers()->where('room_player_id', $me->id)->exists())->toBeTrue();
    });

    it('shows the scores so far, and lands on the reveal if the question already ended', function () {
        [$room, $question, , $me] = phoneGame();
        app(GameEngine::class)->submitAnswer($me, optionOf($question));
        revealNow($room);
        at('12:00:03.000');

        phone($room)->assertSee('Correct!')->assertSee('1st');
    });

    it('moves to the next question when it starts', function () {
        [$room, $question] = phoneGame();
        $page = phone($room)->assertSee('Question 1 of 3');
        revealNow($room);
        app(GameEngine::class)->advance($room, 1, force: true);

        $page->dispatch("echo-presence:room.{$room->code},QuestionStarted")->assertSee('Question 2 of 3')->assertSeeHtml('data-test="answers"');
    });

    it('goes to the join screen when the player is no longer in the room', function () {
        [$room, , , $me] = phoneGame();
        $page = phone($room);
        $me->delete();

        $page->call('$refresh')->assertRedirect(route('join', ['code' => $room->code]));
    });

    it('refreshes on every game event, and when the connection comes back', function () {
        [$room] = phoneGame();

        $listeners = phone($room)->instance()->getListeners();

        foreach (['here', 'QuestionStarted', 'PlayerAnswered', 'QuestionRevealed', 'ScoreboardUpdated', 'GameFinished'] as $event) {
            expect($listeners)->toHaveKey("echo-presence:room.{$room->code},{$event}", '$refresh');
        }
        phone($room)->assertSeeHtml('wire:poll.4s');
    });
});

describe('inside the phone\'s page', function () {
    it('replaces the lobby with the game once the room is playing', function () {
        [$room, $question] = phoneGame();

        Livewire::withCookie('lamma_guest', GAME_TOKEN)->test(PlayerLobby::class, ['room' => $room->fresh()])
            ->assertSeeHtml('data-test="player-game"')->assertSee($question->question->getTranslation('text', 'en'))
            ->assertDontSeeHtml('data-test="ready-button"')->assertDontSeeHtml('data-test="leave-button"');
    });

    it('shows the plain closed card for a room closed in the lobby', function () {
        $room = Room::factory()->create(['status' => RoomStatus::Finished]);
        RoomPlayer::factory()->for($room)->create(['guest_token' => GAME_TOKEN, 'locale' => 'en']);

        Livewire::withCookie('lamma_guest', GAME_TOKEN)->test(PlayerLobby::class, ['room' => $room])
            ->assertSee('This room has been closed.')->assertDontSeeHtml('data-test="player-game"');
    });
});
