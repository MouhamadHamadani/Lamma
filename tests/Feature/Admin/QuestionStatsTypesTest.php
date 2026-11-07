<?php

use App\Enums\QuestionType;
use App\Filament\Resources\Questions\Pages\ListQuestions;
use App\Filament\Widgets\HardestQuestions;
use App\Game\GameEngine;
use App\Game\GameStats;
use App\Game\PlayerStats;
use App\Models\Admin;
use App\Models\PlayerAnswer;
use App\Models\Question;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\RoomQuestion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(Admin::factory()->create(), 'admin');
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00.000'));
});

/**
 * Play one question of each type in a finished game of $players, the way the engine does it: every player taps or types, the question is revealed. $right says who is
 * right on each question (by position in the order True/False, number, choice): the number of right answers out of $players.
 *
 * @param  array{multiple_choice: int, true_false: int, closest_number: int}  $right
 * @return array{0: Room, 1: array<string, Question>}
 */
function playedAllTypes(array $right, int $players = 4): array
{
    $room = typedRoom(['multiple_choice', 'true_false', 'closest_number'], players: $players, settings: ['doubleFinal' => false]);
    $engine = app(GameEngine::class);
    startGame($room);
    $people = $room->players()->orderBy('id')->get();

    foreach ([1, 2, 3] as $position) {
        $roomQuestion = RoomQuestion::query()->where('room_id', $room->id)->where('position', $position)->first();
        $want = $right[$roomQuestion->question->type->value];
        foreach ($people as $i => $person) {
            answer($person, $roomQuestion, correct: $i < $want);
        }
        $engine->advance($room, $position, force: true);
    }

    return [$room->fresh(), Question::query()->get()->keyBy(fn (Question $question) => $question->type->value)->all()];
}

describe('the statistics count every type the same way', function () {
    it('counts a closest-number guess within the tolerance as correct, and the rest as wrong, like a tap', function () {
        [, $questions] = playedAllTypes(['multiple_choice' => 3, 'true_false' => 1, 'closest_number' => 2]);

        foreach (['multiple_choice' => [4, 1], 'true_false' => [4, 3], 'closest_number' => [4, 2]] as $type => [$answers, $wrong]) {
            $row = app(GameStats::class)->hardestQuestionsQuery(10)->get()->firstWhere('id', $questions[$type]->id);

            expect($row->answers_count)->toBe($answers)->and($row->wrong_count)->toBe($wrong)
                ->and(GameStats::percentCorrect($row->answers_count, $row->wrong_count))->toBe((int) round(($answers - $wrong) / $answers * 100));
        }
    });

    it('lists the questions of every type in the most-answered-wrong widget, worst first, with their type', function () {
        [, $questions] = playedAllTypes(['multiple_choice' => 4, 'true_false' => 1, 'closest_number' => 2]);

        $widget = Livewire::test(HardestQuestions::class);

        $widget->assertSuccessful()->assertCanSeeTableRecords([$questions['true_false'], $questions['closest_number']])->assertCanNotSeeTableRecords([$questions['multiple_choice']])
            ->assertTableColumnExists('type')->assertSee('Closest number')->assertSee('True or false')->assertSee('within the tolerance counts as correct')
            ->assertSeeInOrder(['True or false', '25%', 'Closest number', '50%']);
    });

    it('shows the share of correct answers on the question list for a number question', function () {
        [, $questions] = playedAllTypes(['multiple_choice' => 4, 'true_false' => 4, 'closest_number' => 1]);

        Livewire::test(ListQuestions::class)
            ->assertCanSeeTableRecords([$questions['closest_number']])
            ->assertSeeInOrder(['Closest number', '25%'])
            ->assertTableColumnStateSet('percent_correct', '25%', $questions['closest_number'])
            ->assertTableColumnStateSet('percent_correct', '100%', $questions['multiple_choice'])
            ->assertTableColumnStateSet('times_played', 1, $questions['closest_number']);
    });

    it('does not mind a question nobody answered, whatever its type', function () {
        $number = Question::factory()->closestNumber(5, 1)->create();
        $trueFalse = Question::factory()->trueFalse()->create();

        Livewire::test(ListQuestions::class)->assertTableColumnStateSet('percent_correct', '—', $number)->assertTableColumnStateSet('percent_correct', '—', $trueFalse);
        expect(app(GameStats::class)->hardestQuestionsQuery()->get())->toHaveCount(0);
    });

    it('counts a number guess as correct in a player\'s saved totals, as a tap is', function () {
        $user = User::factory()->create();
        $room = typedRoom(['closest_number', 'true_false'], players: 0, settings: ['doubleFinal' => false]);
        $me = RoomPlayer::factory()->for($room)->forUser($user)->create(['is_ready' => true, 'left_at' => null]);
        RoomPlayer::factory()->for($room)->create(['is_ready' => true, 'left_at' => null]);
        $engine = app(GameEngine::class);
        startGame($room);

        foreach ([1, 2] as $position) {
            $roomQuestion = RoomQuestion::query()->where('room_id', $room->id)->where('position', $position)->first();
            answer($me, $roomQuestion, correct: $position === 1);   // the number right, the true/false wrong
            answer($room->players()->where('id', '!=', $me->id)->first(), $roomQuestion);
            $engine->advance($room, $position, force: true);
        }

        $totals = app(PlayerStats::class)->totals($user);
        expect($totals['correct'])->toBe(1)->and($totals['questions'])->toBe(2)->and($totals['rate'])->toBe(50);
    });

    it('keeps the answer table honest: a guess has a number and no option, a tap has an option and no number', function () {
        playedAllTypes(['multiple_choice' => 2, 'true_false' => 2, 'closest_number' => 2]);

        foreach (PlayerAnswer::with('roomQuestion.question')->get() as $answer) {
            $number = $answer->roomQuestion->question->type === QuestionType::ClosestNumber;

            expect($answer->number_value !== null)->toBe($number)->and($answer->question_option_id !== null)->toBe(! $number);
        }
    });
});
