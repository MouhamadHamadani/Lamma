<?php

use App\Enums\HostScreenLocale;
use App\Enums\RoomStatus;
use App\Events\GameFinished;
use App\Events\GameStarted;
use App\Events\PlayerAnswered;
use App\Events\QuestionRevealed;
use App\Events\QuestionStarted;
use App\Events\ScoreboardUpdated;
use App\Game\GameEngine;
use App\Livewire\Host\HostGame;
use App\Livewire\Host\HostLobby;
use App\Models\Room;
use App\Models\RoomQuestion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    Event::fake([GameStarted::class, QuestionStarted::class, PlayerAnswered::class, QuestionRevealed::class, ScoreboardUpdated::class, GameFinished::class]);
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00.000'));
});

function hostGame(Room $room): Testable
{
    return Livewire::actingAs($room->host)->test(HostGame::class, ['room' => $room]);
}

/** A started game (question 1 open, 12:00:00 to 12:00:20) on a host screen set to $mode. */
function hostedGame(HostScreenLocale $mode = HostScreenLocale::Both, int $players = 3, int $questions = 3): array
{
    $room = gameRoom(players: $players, questions: $questions, settings: ['hostScreenLocale' => $mode]);
    $question = startGame($room);

    return [$room, $question, $room->players()->orderBy('id')->get()];
}

function text(RoomQuestion $roomQuestion, string $lang): string
{
    return $roomQuestion->question->getTranslation('text', $lang);
}

describe('the question screen (host-4)', function () {
    it('shows the question in English with the Arabic line under it when the room is set to Both', function () {
        [$room, $question] = hostedGame(HostScreenLocale::Both);

        hostGame($room)
            ->assertSee(text($question, 'en'))->assertSee(text($question, 'ar'))
            ->assertSeeHtml('lang="ar" dir="rtl"')
            ->assertSee('Question 1 of 3');
    });

    it('shows one language only when the room is set to English or Arabic', function () {
        [$room, $question] = hostedGame(HostScreenLocale::En);
        hostGame($room)->assertSee(text($question, 'en'))->assertDontSee(text($question, 'ar'))->assertDontSeeHtml('data-test="question-text-alt"');

        [$room, $question] = hostedGame(HostScreenLocale::Ar);
        hostGame($room)->assertSee(text($question, 'ar'))->assertDontSee(text($question, 'en'))->assertSee('السؤال 1 من 3');
    });

    it('shows the four answers in the stored order, each with its shape, and the Arabic text in Both mode', function () {
        [$room, $question] = hostedGame();
        $options = collect($question->option_order)->map(fn (int $id) => $question->question->options->firstWhere('id', $id));

        $page = hostGame($room);

        foreach ($options as $option) {
            $page->assertSee($option->getTranslation('text', 'en'))->assertSee($option->getTranslation('text', 'ar'))->assertSeeHtml('data-option="'.$option->id.'"');
        }
        $page->assertSeeInOrder($options->map(fn ($option) => $option->getTranslation('text', 'en'))->all());
        expect(substr_count($page->html(), 'viewBox="0 0 18 18"'))->toBeGreaterThanOrEqual(4);
    });

    it('does not say which answer is correct while the question is open', function () {
        [$room, $question] = hostedGame();
        $correct = $question->question->options->firstWhere('is_correct', true);

        $page = hostGame($room);

        $page->assertDontSeeHtml('data-correct')->assertDontSee('Correct')->assertDontSeeHtml('is_correct');
        expect($page->html())->not->toContain('data-option="'.$correct->id.'" data-correct');
    });

    it('draws a timer ring from the server deadline', function () {
        [$room, $question] = hostedGame();

        $page = hostGame($room);

        $page->assertSeeHtml('role="timer"')->assertSeeHtml('lammaTimer('.$question->ends_at->getTimestampMs().', '.now()->getTimestampMs().', 20,');
    });

    it('shows who has answered and how many, leaving out players who are not connected', function () {
        [$room, $question, $players] = hostedGame(players: 3);
        $players[2]->update(['left_at' => now()]);
        answer($players[0], $question);

        hostGame($room)->assertSee('1 of 2 answered')->assertSeeHtml('data-test="answered-avatars"');
    });

    it('lists progress as dots and shows the category chip', function () {
        [$room, $question] = hostedGame();

        $page = hostGame($room);

        $page->assertSee($question->question->category->getTranslation('name', 'en'))->assertSeeHtml('data-test="category"');
        expect($page->html())->toContain('bg-coral'); // the current dot
    });

    it('has Skip timer and Close room, but no Next question while a question is open', function () {
        [$room] = hostedGame();

        hostGame($room)->assertSee('Skip timer')->assertSee('Close room')->assertDontSeeHtml('data-test="next-button"');
    });

    it('schedules a tick for the moment the reveal is due, plus the grace', function () {
        [$room] = hostedGame();

        hostGame($room)->assertSeeHtml('x-init="setTimeout(() => $wire.tick(), 20650)"');
    });
});

describe('the reveal screen (host-5)', function () {
    it('marks the correct tile, fades the others and shows who picked what', function () {
        [$room, $question, $players] = hostedGame();
        $correct = $question->question->options->firstWhere('is_correct', true);
        $wrong = $question->question->options->firstWhere('is_correct', false);
        answer($players[0], $question);
        answer($players[1], $question, correct: false);
        at('12:00:20.600');
        app(GameEngine::class)->tick($room);

        $page = hostGame($room);

        $page->assertSee('Answer · Question 1')->assertSee('Correct')->assertSeeHtml('data-option="'.$correct->id.'" data-correct="true"')->assertSeeHtml('data-option="'.$wrong->id.'" data-correct="false"');
        expect(substr_count($page->html(), 'opacity-35'))->toBe(3)->and($page->html())->toContain($players[0]->nickname)->and($page->html())->toContain($players[1]->nickname);
        $page->assertSee('1 of 3 got it right');
    });

    it('shows the scoreboard with the points gained this round, best first', function () {
        [$room, $question, $players] = hostedGame();
        answer($players[1], $question);
        at('12:00:20.600');
        app(GameEngine::class)->tick($room);

        $page = hostGame($room)->assertSee('Scoreboard')->assertSeeHtml('data-test="scoreboard"');

        $page->assertSeeInOrder([$players[1]->nickname, '100']);
        expect(substr_count($page->html(), 'data-test="gained"'))->toBe(3)->and($page->html())->toContain('lammaCountUp(100)')->and(substr_count($page->html(), 'lammaCountUp(0)'))->toBe(2);
    });

    it('counts down to the next question and offers Next question', function () {
        [$room, $question, $players] = hostedGame();
        answer($players[0], $question);
        at('12:00:20.600');
        app(GameEngine::class)->tick($room);

        hostGame($room)->assertSee('Next question in')->assertSeeHtml('data-test="next-countdown"')->assertSee('Next question')->assertSeeHtml('data-test="next-button"');
    });

    it('says the game ends rather than the next question on the last question', function () {
        [$room, $question] = hostedGame(questions: 1);
        at('12:00:20.600');
        app(GameEngine::class)->tick($room);

        hostGame($room)->assertSee('Game ends in')->assertSee('Finish game')->assertDontSee('Next question');
    });
});

describe('keeping the game moving (tick)', function () {
    it('reveals once the time is up and the grace has passed, not before', function () {
        [$room, $question] = hostedGame();
        $page = hostGame($room);

        at('12:00:20.400');
        $page->call('tick');
        expect($question->fresh()->revealed_at)->toBeNull();

        at('12:00:20.500');
        $page->call('tick')->assertSee('Answer · Question 1');
        expect($question->fresh()->revealed_at)->not->toBeNull();
    });

    it('moves on to the next question once the reveal pause is over', function () {
        [$room, $question] = hostedGame();
        $page = hostGame($room);
        at('12:00:20.500');
        $page->call('tick');

        at('12:00:25.400');
        $page->call('tick');
        expect($room->fresh()->currentQuestion()->position)->toBe(1);

        at('12:00:25.500');
        $page->call('tick')->assertSee('Question 2 of 3');
        expect($room->fresh()->currentQuestion()->position)->toBe(2);
    });

    it('does nothing twice', function () {
        [$room] = hostedGame();
        $page = hostGame($room);
        at('12:00:20.500');

        $page->call('tick')->call('tick')->call('tick');

        expect($room->fresh()->currentQuestion()->position)->toBe(1);
        Event::assertDispatchedTimes(QuestionRevealed::class, 1);
    });

    it('polls, so it catches up even when an event is missed', function () {
        [$room] = hostedGame();

        hostGame($room)->assertSeeHtml('wire:poll.2s="tick"');
    });

    it('refreshes on every game event and on a player joining or leaving', function () {
        [$room] = hostedGame();

        $listeners = hostGame($room)->instance()->getListeners();

        foreach (['QuestionStarted', 'PlayerAnswered', 'QuestionRevealed', 'ScoreboardUpdated', 'GameFinished', 'joining', 'leaving'] as $event) {
            expect($listeners)->toHaveKey("echo-presence:room.{$room->code},{$event}", '$refresh');
        }
    });
});

describe('the host\'s buttons', function () {
    it('Skip timer reveals now, and a late click on an old question does nothing', function () {
        [$room, $question] = hostedGame();
        $page = hostGame($room);

        $page->call('skipTimer', 1)->assertSee('Answer · Question 1');
        expect($question->fresh()->revealed_at)->not->toBeNull();

        $page->call('skipTimer', 1)->call('skipTimer', 7);
        Event::assertDispatchedTimes(QuestionRevealed::class, 1);
    });

    it('Next question moves on straight away, and clicking twice moves on once', function () {
        [$room] = hostedGame();
        $page = hostGame($room)->call('skipTimer', 1);

        $page->call('next', 1)->call('next', 1)->assertSee('Question 2 of 3');

        expect($room->fresh()->currentQuestion()->position)->toBe(2);
        Event::assertDispatchedTimes(QuestionStarted::class, 2);
    });

    it('Finish game ends the game after the last question', function () {
        [$room] = hostedGame(questions: 1);

        hostGame($room)->call('skipTimer', 1)->call('next', 1)->assertSee("That's the game!")->assertSee('Here are the final scores.')->assertSeeHtml('data-test="host-again"');

        expect($room->fresh()->status)->toBe(RoomStatus::Finished);
        Event::assertDispatchedTimes(GameFinished::class, 1);
    });

    it("are the host's alone", function () {
        [$room] = hostedGame();

        foreach ([['tick', null], ['skipTimer', 1], ['next', 1], ['closeRoom', null]] as [$action, $argument]) {
            Livewire::actingAs(User::factory()->create())->test(HostGame::class, ['room' => $room])->call($action, ...($argument ? [$argument] : []))->assertForbidden();
        }

        expect($room->fresh()->currentQuestion()->revealed_at)->toBeNull()->and($room->fresh()->status)->toBe(RoomStatus::Playing);
    });

    it('Close room finishes the room and sends the host to create another', function () {
        [$room] = hostedGame();

        hostGame($room)->call('closeRoom')->assertRedirect(route('rooms.create'));

        expect($room->fresh()->status)->toBe(RoomStatus::Finished);
    });
});

describe('after a refresh or a reconnect', function () {
    it('rebuilds the question from the database: same question, time left from ends_at, answers so far', function () {
        [$room, $question, $players] = hostedGame();
        answer($players[0], $question);
        at('12:00:12.000');

        $page = hostGame($room);

        $page->assertSee(text($question, 'en'))->assertSee('Question 1 of 3')->assertSee('1 of 3 answered')
            ->assertSeeHtml('lammaTimer('.$question->ends_at->getTimestampMs().', '.now()->getTimestampMs().', 20,')
            ->assertSeeHtml('x-init="setTimeout(() => $wire.tick(), 8650)"');
    });

    it('rebuilds the reveal, with the pause that is left', function () {
        [$room, $question, $players] = hostedGame();
        answer($players[0], $question);
        at('12:00:20.500');
        app(GameEngine::class)->tick($room);
        at('12:00:23.000');

        hostGame($room)->assertSee('Answer · Question 1')->assertSee('Scoreboard')->assertSeeHtml('x-init="setTimeout(() => $wire.tick(), 2650)"');
    });

    it('lands on the right question later in the game', function () {
        [$room] = hostedGame();
        app(GameEngine::class)->reveal($room, 1, force: true);
        app(GameEngine::class)->advance($room, 1, force: true);

        hostGame($room)->assertSee('Question 2 of 3');
    });

    it('does not wait for players who are not connected: only connected players are expected', function () {
        [$room, $question, $players] = hostedGame(players: 3);
        $players[1]->update(['left_at' => now()]);
        $players[2]->update(['left_at' => now()]);

        hostGame($room)->assertSee('0 of 1 answered');
    });
});

describe('inside the lobby page', function () {
    it('replaces the lobby with the game once the room is playing', function () {
        [$room, $question] = hostedGame();

        Livewire::actingAs($room->host)->test(HostLobby::class, ['room' => $room->fresh()])
            ->assertSeeHtml('data-test="host-game"')->assertSee(text($question, 'en'))->assertDontSeeHtml('data-test="start-button"')->assertDontSeeHtml('wire:poll.5s="pruneDisconnected"');
    });
});
