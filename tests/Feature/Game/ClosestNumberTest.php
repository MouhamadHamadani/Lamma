<?php

use App\Events\GameFinished;
use App\Events\GameStarted;
use App\Events\PlayerAnswered;
use App\Events\QuestionRevealed;
use App\Events\QuestionStarted;
use App\Events\ScoreboardUpdated;
use App\Game\AnswerRejected;
use App\Game\GameEngine;
use App\Game\GameView;
use App\Game\Points;
use App\Game\ScoringService;
use App\Game\Transition;
use App\Models\PlayerAnswer;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\RoomQuestion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Event::fake([GameStarted::class, QuestionStarted::class, PlayerAnswered::class, QuestionRevealed::class, ScoreboardUpdated::class, GameFinished::class]);
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00.000'));
});

/** A game of one closest-number question (1969, tolerance 10) with $players connected players, question 1 open 12:00:00 to 12:00:20. */
function numberGame(int $players = 4, array $settings = ['doubleFinal' => false], int $questions = 1): array
{
    $room = typedRoom(array_fill(0, $questions, 'closest_number'), $players, $settings);
    $question = startGame($room);

    return [$room, $question, $room->players()->orderBy('id')->get()];
}

function tryGuess(RoomPlayer $player, string|int|float $typed): ?string
{
    try {
        guess($player, $typed);
    } catch (AnswerRejected $rejected) {
        return $rejected->reason;
    }

    return null;
}

function revealGame(Room $room, int $position = 1): Transition
{
    return app(GameEngine::class)->reveal($room, $position, force: true);
}

describe('the points for a guess', function () {
    $scoring = fn () => app(ScoringService::class);

    it('is 100 for the answer itself, falling in a straight line to 0 at the tolerance', function (float $guess, int $base) use ($scoring) {
        $points = $scoring()->closestPoints($guess, 1969, 10);

        expect($points)->toBeInstanceOf(Points::class)
            ->and($points->base)->toBe($base)->and($points->speedBonus)->toBe(0)->and($points->multiplier)->toBe(1)->and($points->total)->toBe($base);
    })->with([
        'exact' => [1969.0, 100],
        'one off' => [1970.0, 90],
        'one under' => [1968.0, 90],
        'three off' => [1966.0, 70],
        'half way' => [1964.0, 50],
        'nine off' => [1960.0, 10],
        'exactly the tolerance' => [1979.0, 0],
        'just beyond' => [1980.0, 0],
        'far beyond' => [1000.0, 0],
        'a decimal' => [1969.5, 95],
    ]);

    it('rounds an exact half point up', function () use ($scoring) {
        expect($scoring()->closestPoints(101, 100, 8)->base)->toBe(88); // 87.5
        expect($scoring()->closestPoints(103, 100, 8)->base)->toBe(63); // 62.5
    });

    it('counts the last question double, base and all', function () use ($scoring) {
        expect($scoring()->closestPoints(1969, 1969, 10, finalQuestion: true)->total)->toBe(200)
            ->and($scoring()->closestPoints(1964, 1969, 10, finalQuestion: true)->multiplier)->toBe(2)
            ->and($scoring()->closestPoints(1964, 1969, 10, finalQuestion: true)->total)->toBe(100);
    });

    it('does not mind float noise, and does not divide by zero', function () use ($scoring) {
        expect($scoring()->closestPoints(0.1 + 0.2, 0.3, 1)->base)->toBe(100)
            ->and($scoring()->closestPoints(5, 5, 0)->base)->toBe(100)
            ->and($scoring()->closestPoints(6, 5, 0)->base)->toBe(0);
    });

    it('measures the distance to four decimals', function () use ($scoring) {
        expect($scoring()->distance(1969.00004, 1969))->toBe(0.0)->and($scoring()->distance(1, 1.5))->toBe(0.5)->and($scoring()->distance(-3, 4))->toBe(7.0);
    });

    it('gives the extra to the nearest of the guesses that scored, and to all of them on a tie', function () use ($scoring) {
        $answers = fn (array $guesses) => new Collection(array_map(function (float $guess, int $index) {
            $answer = new PlayerAnswer(['number_value' => $guess, 'is_correct' => abs($guess - 1969) < 10]);
            $answer->id = $index + 1;

            return $answer;
        }, $guesses, array_keys($guesses)));

        expect($scoring()->closestWinners($answers([1960, 1966, 1975]), 1969))->toBe([2])
            ->and($scoring()->closestWinners($answers([1966, 1972, 1990]), 1969))->toBe([1, 2])
            ->and($scoring()->closestWinners($answers([1969, 1969, 1970]), 1969))->toBe([1, 2])
            ->and($scoring()->closestWinners($answers([1990, 2000]), 1969))->toBe([])
            ->and($scoring()->closestWinners($answers([]), 1969))->toBe([]);
    });
});

describe('guessing', function () {
    it('locks the guess at once: the number, whether it scored, and what it is worth, with no option', function () {
        [, $question, $players] = numberGame();

        $given = guess($players[0], '1966');

        expect($given->question_option_id)->toBeNull()->and($given->number_value)->toBe(1966.0)->and($given->is_correct)->toBeTrue()
            ->and($given->base_points)->toBe(70)->and($given->speed_bonus)->toBe(0)->and($given->multiplier)->toBe(1)->and($given->points)->toBe(70)->and($given->closest_bonus)->toBeNull()
            ->and($question->answers()->count())->toBe(1);
    });

    it('is wrong outside the tolerance, and scores nothing', function () {
        [, , $players] = numberGame();

        $given = guess($players[0], 1000);

        expect($given->is_correct)->toBeFalse()->and($given->points)->toBe(0)->and($given->base_points)->toBe(0);
    });

    it('reads Arabic digits and separators, and spaces', function (string $typed, float $value) {
        [, , $players] = numberGame();

        expect(guess($players[0], $typed)->number_value)->toBe($value);
    })->with([
        'Arabic-Indic digits' => ['١٩٦٩', 1969.0],
        'with spaces' => ['  1 969 ', 1969.0],
        'a thousands comma' => ['1,969', 1969.0],
        'an Arabic decimal point' => ['١٩٦٨٫٥', 1968.5],
        'a decimal comma' => ['1968,5', 1968.5],
    ]);

    it('refuses what is not a number, and lets the player try again', function (string $typed) {
        [, $question, $players] = numberGame();

        expect(tryGuess($players[0], $typed))->toBe(AnswerRejected::INVALID_NUMBER)
            ->and(PlayerAnswer::count())->toBe(0)
            ->and(tryGuess($players[0], '1969'))->toBeNull()
            ->and($question->answers()->count())->toBe(1);
    })->with(['letters' => 'abc', 'empty' => '', 'a sentence' => 'about 1969', 'an exponent' => '1e3', 'two points' => '1.2.3', 'too big' => '9999999999999']);

    it('takes one guess per player and never changes it', function () {
        [, , $players] = numberGame();

        guess($players[0], 1966);

        expect(tryGuess($players[0], 1969))->toBe(AnswerRejected::ALREADY_ANSWERED)
            ->and(PlayerAnswer::count())->toBe(1)->and(PlayerAnswer::first()->number_value)->toBe(1966.0);
    });

    it('follows the timing rules of any answer: until the deadline plus the grace, never after the reveal', function () {
        [$room, , $players] = numberGame();

        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:20.400'));
        expect(tryGuess($players[0], 1969))->toBeNull(); // inside the grace

        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:20.501'));
        expect(tryGuess($players[1], 1969))->toBe(AnswerRejected::LATE);

        revealGame($room);
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:21.000'));
        expect(tryGuess($players[2], 1969))->toBe(AnswerRejected::CLOSED);
    });

    it('pays no speed bonus: a quick guess and a slow one are worth the same', function () {
        [, , $players] = numberGame();

        $quick = guess($players[0], 1969);
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:19.000'));
        $slow = guess($players[1], 1969);

        expect($quick->points)->toBe(100)->and($slow->points)->toBe(100)->and($quick->speed_bonus)->toBe(0)->and($slow->speed_bonus)->toBe(0);
    });

    it('is never an option tap: a number on a choice question, or an option on a number question, is refused', function () {
        $room = typedRoom(['multiple_choice', 'closest_number'], players: 2);
        $first = startGame($room);
        $players = $room->players()->orderBy('id')->get();

        expect(tryGuess($players[0], '1969'))->toBe(AnswerRejected::INVALID_OPTION)
            ->and(tryGuess($players[0], 4.5))->toBe(AnswerRejected::INVALID_OPTION)
            ->and(tryGuess($players[0], 99999))->toBe(AnswerRejected::INVALID_OPTION)
            ->and(PlayerAnswer::count())->toBe(0)
            ->and(tryGuess($players[0], optionOf($first)))->toBeNull();
    });
});

describe('the reveal of a closest-number question', function () {
    it('adds the answer points to the score only at the reveal, plus 50 for the closest guess', function () {
        [$room, , $players] = numberGame(players: 5);   // (the fifth never answers, so the timer decides)
        guess($players[0], 1966);   // 70, 3 away
        guess($players[1], 1969);   // 100, exact: the closest
        guess($players[2], 1960);   // 10, 9 away
        guess($players[3], 1000);   // 0

        expect($players->map(fn ($p) => $p->fresh()->score)->all())->toBe([0, 0, 0, 0, 0]);

        expect(revealGame($room))->toBe(Transition::Done);

        $answers = PlayerAnswer::query()->orderBy('room_player_id')->get();
        expect($answers->pluck('points')->all())->toBe([70, 150, 10, 0])
            ->and($answers->pluck('closest_bonus')->all())->toBe([null, 50, null, null])
            ->and($answers->pluck('is_correct')->all())->toBe([true, true, true, false])
            ->and($players->map(fn ($p) => $p->fresh()->score)->all())->toBe([70, 150, 10, 0, 0]);
    });

    it('shares the extra between guesses the same distance away, above or below', function () {
        [$room, , $players] = numberGame();
        guess($players[0], 1966);   // 3 below
        guess($players[1], 1972);   // 3 above
        guess($players[2], 1960);

        revealGame($room);

        expect($players->map(fn ($p) => $p->fresh()->score)->all())->toBe([120, 120, 10, 0]);
    });

    it('gives the extra to the one guess that scored, even if it is the only one', function () {
        [$room, , $players] = numberGame();
        guess($players[0], 1975);   // 6 away: 40
        guess($players[1], 1000);

        revealGame($room);

        expect($players[0]->fresh()->score)->toBe(90)->and($players[1]->fresh()->score)->toBe(0);
    });

    it('gives no extra when nobody was within the tolerance, and does not mind nobody guessing', function () {
        [$room, , $players] = numberGame();
        guess($players[0], 1000);
        guess($players[1], 3000);

        expect(revealGame($room))->toBe(Transition::Done)
            ->and(PlayerAnswer::whereNotNull('closest_bonus')->count())->toBe(0)
            ->and($players->sum(fn ($p) => $p->fresh()->score))->toBe(0);

        [$other] = numberGame();
        expect(revealGame($other))->toBe(Transition::Done);
    });

    it('counts the last question double, the extra included', function () {
        [$room, , $players] = numberGame(settings: ['doubleFinal' => true]);
        guess($players[0], 1969);   // (100 + 50) x 2
        guess($players[1], 1964);   // 50 x 2

        revealGame($room);

        $answers = PlayerAnswer::query()->orderBy('room_player_id')->get();
        expect($answers[0]->points)->toBe(300)->and($answers[0]->multiplier)->toBe(2)->and($answers[1]->points)->toBe(100)
            ->and($players[0]->fresh()->score)->toBe(300)->and($players[1]->fresh()->score)->toBe(100);
    });

    it('does not double the extra by revealing twice', function () {
        [$room, , $players] = numberGame();
        guess($players[0], 1969);

        revealGame($room);
        expect(revealGame($room))->toBe(Transition::Already)
            ->and($players[0]->fresh()->score)->toBe(150)->and(PlayerAnswer::first()->points)->toBe(150);
    });

    it('counts a guess within the tolerance as correct for the statistics, and the rest as wrong', function () {
        [$room, , $players] = numberGame();
        guess($players[0], 1975);
        guess($players[1], 1000);
        revealGame($room);

        $state = app(GameView::class)->forRoom($room->fresh());

        expect($state->correctCount())->toBe(1)->and($state->answered)->toBe(2);
    });
});

describe('what the screens are given', function () {
    it('shows the number, every guess nearest first with its distance and points, and who got the extra, in the reveal event', function () {
        [$room, , $players] = numberGame();
        guess($players[0], 1966);
        guess($players[1], 1969);
        guess($players[2], 1000);
        revealGame($room);

        $payload = Event::dispatched(QuestionRevealed::class)->last()[0]->broadcastWith();

        expect($payload['type'])->toBe('closest_number')->and($payload['correct_number'])->toBe(1969.0)->and($payload['correct_option_id'])->toBeNull()
            ->and($payload['picks'])->toBe([])
            ->and(array_column($payload['guesses'], 'value'))->toBe([1969.0, 1966.0, 1000.0])
            ->and(array_column($payload['guesses'], 'distance'))->toBe([0.0, 3.0, 969.0])
            ->and(array_column($payload['guesses'], 'points'))->toBe([150, 70, 0])
            ->and(array_column($payload['guesses'], 'closest'))->toBe([true, false, false])
            ->and($payload['guesses'][0]['player'])->toHaveKeys(['id', 'nickname'])
            ->and(collect($payload['points'])->pluck('points', 'player_id')->all())->toBe([$players[0]->id => 70, $players[1]->id => 150, $players[2]->id => 0, $players[3]->id => 0])
            ->and($payload['explanation'])->toBe(['ar' => 'معلومة طريفة.', 'en' => 'A fun fact.']);
    });

    it('has no number and no guesses in the reveal of the other types', function () {
        $room = typedRoom(['multiple_choice', 'true_false']);
        startGame($room);
        revealGame($room);

        $payload = Event::dispatched(QuestionRevealed::class)->last()[0]->broadcastWith();

        expect($payload['type'])->toBe('multiple_choice')->and($payload['correct_number'])->toBeNull()->and($payload['guesses'])->toBe([])->and($payload['correct_option_id'])->not->toBeNull();
    });

    it('keeps the answer out of the answered event: it says who, never what', function () {
        [, , $players] = numberGame();
        guess($players[0], 1969);

        $payload = Event::dispatched(PlayerAnswered::class)->last()[0]->broadcastWith();

        expect($payload['answered'])->toBe(1)->and($payload['expected'])->toBe(4)
            ->and(json_encode($payload))->not->toContain('1969')->not->toContain('value')->not->toContain('number')->not->toContain('guess');
    });

    it('builds the host and phone state with the number and guesses only after the reveal', function () {
        [$room, , $players] = numberGame();
        guess($players[0], 1966);
        guess($players[1], 1969);
        $view = app(GameView::class);

        $open = $view->forRoom($room->fresh());
        expect($open->correctNumber)->toBeNull()->and($open->guesses)->toBe([])->and($open->fact('en'))->toBeNull()->and($open->isNumber())->toBeTrue();

        revealGame($room);
        $revealed = $view->forRoom($room->fresh());

        expect($revealed->correctNumber)->toBe(1969.0)
            ->and(array_column($revealed->guesses, 'value'))->toBe([1969.0, 1966.0])
            ->and($revealed->guesses[0]['player']->is($players[1]))->toBeTrue()
            ->and($revealed->guesses[0]['closest'])->toBeTrue()->and($revealed->guesses[1]['closest'])->toBeFalse()
            ->and($revealed->fact('en'))->toBe('A fun fact.')->and($revealed->fact('ar'))->toBe('معلومة طريفة.');
    });
});

describe('revealing early when everyone has answered, whatever the types', function () {
    it('reveals each question the moment the last connected player has answered', function () {
        $room = typedRoom(['multiple_choice', 'true_false', 'closest_number'], players: 3);
        $engine = app(GameEngine::class);
        startGame($room);
        $players = $room->players()->orderBy('id')->get();
        $players[2]->update(['left_at' => now()]);   // not waited for

        foreach ([1, 2, 3] as $position) {
            $roomQuestion = fn () => $room->roomQuestions()->where('position', $position)->first();

            answer($players[0], $roomQuestion());
            expect($roomQuestion()->revealed_at)->toBeNull();   // one of two connected players

            answer($players[1], $roomQuestion(), correct: false);
            expect($roomQuestion()->revealed_at)->not->toBeNull();   // revealed at once, without waiting for the timer

            if ($position < 3) {
                $engine->advance($room, $position, force: true);
            }
        }
    });

    it('waits for a connected player who has not answered a number question', function () {
        [$room, $question, $players] = numberGame(players: 2);

        guess($players[0], 1969);

        expect($question->fresh()->revealed_at)->toBeNull();

        guess($players[1], 1900);

        expect($question->fresh()->revealed_at)->not->toBeNull();
    });

    it('counts an answer from a player who then drops, for any type', function () {
        $room = typedRoom(['closest_number', 'true_false'], players: 2);
        startGame($room);
        $players = $room->players()->orderBy('id')->get();

        answer($players[0], $room->roomQuestions()->first());
        $players[0]->update(['left_at' => now()]);

        // only player 2 is connected now, and has not answered: not revealed
        expect($room->roomQuestions()->first()->fresh()->revealed_at)->toBeNull();

        answer($players[1], $room->roomQuestions()->first());

        expect($room->roomQuestions()->first()->fresh()->revealed_at)->not->toBeNull();
    });
});

describe('a whole game of all three types', function () {
    it('plays through question by question and finishes, with the scores adding up', function () {
        $room = typedRoom(['multiple_choice', 'true_false', 'closest_number'], players: 2, settings: ['doubleFinal' => false]);
        $engine = app(GameEngine::class);
        startGame($room);
        [$ana, $ben] = $room->players()->orderBy('id')->get()->all();

        foreach ([1, 2, 3] as $position) {
            $roomQuestion = RoomQuestion::query()->where('room_id', $room->id)->where('position', $position)->first();
            answer($ana, $roomQuestion);
            answer($ben, $roomQuestion, correct: $position === 3);
            $engine->advance($room, $position, force: true);
        }

        $room = $room->fresh();
        $scores = $room->players()->orderBy('id')->pluck('score')->all();
        $answers = PlayerAnswer::query()->get();

        expect($room->status->value)->toBe('finished')
            ->and($answers)->toHaveCount(6)
            ->and($answers->where('is_correct', true)->count())->toBe(4)   // Ana x3, Ben on the number
            ->and($scores[0])->toBeGreaterThan($scores[1])
            ->and($scores[1])->toBe(150);   // 100 for the number + 50 as the (shared) closest
    });
});
