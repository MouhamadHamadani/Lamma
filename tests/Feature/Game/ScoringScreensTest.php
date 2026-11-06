<?php

use App\Enums\HostScreenLocale;
use App\Events\GameFinished;
use App\Events\GameStarted;
use App\Events\PlayerAnswered;
use App\Events\QuestionRevealed;
use App\Events\QuestionStarted;
use App\Events\ScoreboardUpdated;
use App\Game\GameEngine;
use App\Livewire\Host\HostGame;
use App\Livewire\Player\PlayerGame;
use App\Models\PlayerAnswer;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\RoomQuestion;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

const SCORE_TOKEN = 'zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz';

beforeEach(function () {
    Event::fake([GameStarted::class, QuestionStarted::class, PlayerAnswered::class, QuestionRevealed::class, ScoreboardUpdated::class, GameFinished::class]);
    Queue::fake();
    at('12:00:00.000');
});

/**
 * A 2-question game with the phone's player (first) and another. $final moves it on to the last question.
 *
 * @return array{0: Room, 1: RoomQuestion, 2: RoomPlayer}
 */
function scoreGame(string $locale = 'en', bool $final = false, array $settings = []): array
{
    $room = gameRoom(players: 2, questions: 2, settings: $settings);
    $me = $room->players()->orderBy('id')->first();
    $me->update(['guest_token' => SCORE_TOKEN, 'locale' => $locale]);
    $question = startGame($room);

    if ($final) {
        app(GameEngine::class)->reveal($room, 1, force: true);
        app(GameEngine::class)->advance($room, 1, force: true);
        $question = $room->fresh()->currentQuestion();
    }

    return [$room, $question, $me->fresh()];
}

/** The phone answers right at 12:00:10 (half of the 20 s, so a bonus of 55) and the answer is shown. */
function answeredAtHalfTime(Room $room, RoomQuestion $question, RoomPlayer $me, bool $correct = true): void
{
    test()->travelTo($question->started_at->addSeconds(10));
    answer($me, $question, $correct);
    app(GameEngine::class)->reveal($room, $question->position, force: true);
}

function scorePhone(Room $room): string
{
    return Livewire::withCookie('lamma_guest', SCORE_TOKEN)->test(PlayerGame::class, ['room' => $room])->html();
}

function scoreHost(Room $room): string
{
    return Livewire::actingAs($room->host)->test(HostGame::class, ['room' => $room])->html();
}

describe('the phone reveal', function () {
    it('shows the real +N and how it was made up', function () {
        [$room, $question, $me] = scoreGame();
        answeredAtHalfTime($room, $question, $me);

        $html = scorePhone($room);

        expect($html)->toContain('lammaCountUp(155, 400, true)')
            ->toContain('data-test="points-breakdown"')
            ->toContain('<bdi dir="ltr">100</bdi> + <bdi dir="ltr">55</bdi> speed')
            ->toMatch('/data-test="announcer">\s*Correct, plus 155 points\s*</')
            ->not->toContain('data-test="double-chip"');
    });

    it('says it in Arabic to an Arabic player', function () {
        [$room, $question, $me] = scoreGame('ar');
        answeredAtHalfTime($room, $question, $me);

        expect(scorePhone($room))->toContain('<bdi dir="ltr">100</bdi> + <bdi dir="ltr">55</bdi> للسرعة')->toContain('lammaCountUp(155, 400, true)');
    });

    it('adds x2 final question on the last question, in both languages', function (string $locale, string $line, string $announcement) {
        [$room, $question, $me] = scoreGame($locale, final: true);
        answeredAtHalfTime($room, $question, $me);

        $html = scorePhone($room);

        expect($html)->toContain('lammaCountUp(310, 400, true)')
            ->toContain('data-test="double-chip"')
            ->toContain('>x2<')
            ->toContain($line)
            ->toContain($announcement);
    })->with([
        'English' => ['en', 'final question', 'Double points'],
        'Arabic' => ['ar', 'السؤال الأخير', 'نقاط مضاعفة'],
    ]);

    it('keeps +0 and no breakdown for a wrong answer, even on the last question', function () {
        [$room, $question, $me] = scoreGame(final: true);
        answeredAtHalfTime($room, $question, $me, false);

        $html = scorePhone($room);

        expect($html)->toContain('lammaCountUp(0, 400, false)')->not->toContain('data-test="points-breakdown"')->not->toContain('data-test="double-chip"');
    });

    it('keeps +0 and no breakdown when time ran out', function () {
        [$room] = scoreGame();
        app(GameEngine::class)->reveal($room, 1, force: true);

        $html = scorePhone($room);

        expect($html)->toContain('data-test="reveal-timeout"')->toContain('lammaCountUp(0, 400, false)')->not->toContain('data-test="points-breakdown"');
    });

    it('shows a grace-period answer as 100 + 0', function () {
        [$room, $question, $me] = scoreGame();
        test()->travelTo($question->ends_at->addMilliseconds(300));
        answer($me, $question);
        app(GameEngine::class)->reveal($room, 1, force: true);

        expect(scorePhone($room))->toContain('lammaCountUp(100, 400, true)')->toContain('<bdi dir="ltr">100</bdi> + <bdi dir="ltr">0</bdi> speed');
    });

    it('shows an answer from before the speed bonus without a breakdown', function () {
        [$room, $question, $me] = scoreGame();
        PlayerAnswer::create(['room_question_id' => $question->id, 'room_player_id' => $me->id, 'question_option_id' => optionOf($question), 'answered_at' => now(), 'is_correct' => true, 'points' => 100]);
        app(GameEngine::class)->reveal($room, 1, force: true);

        expect(scorePhone($room))->toContain('lammaCountUp(100, 400, true)')->not->toContain('data-test="points-breakdown"');
    });
});

describe('the x2 chip', function () {
    it('is on the phone question screen and announced, only on the last question', function () {
        [$room] = scoreGame();
        expect(scorePhone($room))->not->toContain('data-test="double-chip"')->toMatch('/data-test="announcer">\s*Question 1 of 2\s*</');

        [$room] = scoreGame(final: true);
        expect(scorePhone($room))->toContain('data-test="double-chip"')->toMatch('/data-test="announcer">\s*Question 2 of 2\. Double points\s*</');
    });

    it('is on the host question screen and announced, only on the last question', function () {
        [$room] = scoreGame();
        expect(scoreHost($room))->not->toContain('data-test="double-chip"')->toMatch('/data-test="announcer">\s*Question 1 of 2\s*</');

        [$room] = scoreGame(final: true);
        expect(scoreHost($room))->toContain('data-test="double-chip"')->toMatch('/data-test="announcer">\s*Question 2 of 2\. Double points\s*</');
    });

    it('speaks Arabic on an Arabic big screen', function () {
        [$room] = scoreGame(final: true, settings: ['hostScreenLocale' => HostScreenLocale::Ar]);

        expect(scoreHost($room))->toContain('data-test="double-chip"')->toContain('نقاط مضاعفة');
    });

    it('is on the host reveal, with the real numbers on the scoreboard', function () {
        [$room, $question, $me] = scoreGame(final: true);
        answeredAtHalfTime($room, $question, $me);

        $html = scoreHost($room);

        expect($html)->toContain('data-test="double-chip"')
            ->toContain('lammaCountUp(310, 400, true)')
            ->toMatch('/data-test="announcer">\s*The correct answer is .*\. Double points\s*</');
    });

    it('never shows when the host turned the setting off', function () {
        [$room, $question, $me] = scoreGame(final: true, settings: ['doubleFinal' => false]);

        expect(scorePhone($room))->not->toContain('data-test="double-chip"')->not->toContain('Double points');
        expect(scoreHost($room))->not->toContain('data-test="double-chip"')->not->toContain('Double points');

        answeredAtHalfTime($room, $question, $me);

        expect(scorePhone($room))->toContain('lammaCountUp(155, 400, true)')->not->toContain('data-test="double-chip"');
        expect(scoreHost($room))->not->toContain('data-test="double-chip"');
    });
});

describe('nothing says +100 any more', function () {
    it('has no hard-coded +100 on a game screen', function () {
        foreach (['livewire/player/player-game', 'livewire/host/host-game', 'components/lamma/scoreboard', 'components/lamma/streak-chip'] as $view) {
            expect(file_get_contents(resource_path("views/{$view}.blade.php")))->not->toContain('+100')->not->toContain('flat');
        }
    });
});
