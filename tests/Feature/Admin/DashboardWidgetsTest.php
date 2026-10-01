<?php

use App\Enums\RoomStatus;
use App\Filament\Resources\Questions\Pages\ListQuestions;
use App\Filament\Widgets\ActiveRooms;
use App\Filament\Widgets\GamesPerDayChart;
use App\Filament\Widgets\HardestQuestions;
use App\Filament\Widgets\MostPlayedCategoriesChart;
use App\Game\GameStats;
use App\Models\Admin;
use App\Models\Category;
use App\Models\PlayerAnswer;
use App\Models\Question;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\RoomQuestion;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(Admin::factory()->create(), 'admin');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
});

/** A room in which $question was asked, with $correct right and $wrong wrong answers from different players. */
function askedWith(Question $question, int $correct, int $wrong, bool $started = true): RoomQuestion
{
    $room = Room::factory()->finished()->create();
    $asked = RoomQuestion::factory()->for($room)->create(['question_id' => $question->id, 'started_at' => $started ? now() : null]);

    foreach (range(1, $correct + $wrong) as $n) {
        PlayerAnswer::factory()->create([
            'room_question_id' => $asked->id,
            'room_player_id' => RoomPlayer::factory()->for($room)->create()->id,
            'is_correct' => $n <= $correct,
        ]);
    }

    return $asked;
}

/** A finished game with every question revealed, ended at $finishedAt. */
function endedAt(string $finishedAt): Room
{
    $room = Room::factory()->finished()->create(['finished_at' => $finishedAt]);
    RoomQuestion::factory()->for($room)->create(['started_at' => $finishedAt, 'revealed_at' => $finishedAt]);

    return $room;
}

describe('games per day', function () {
    it('has an entry for each of the last 30 days, today last, zero where nothing was played', function () {
        $games = app(GameStats::class)->gamesPerDay(30);

        expect($games)->toHaveCount(30)->and(array_key_first($games))->toBe('2026-09-16')->and(array_key_last($games))->toBe('2026-10-15')
            ->and(array_sum($games))->toBe(0);
    });

    it('counts games that ran to the end on the day they ended', function () {
        endedAt('2026-10-15 09:00:00');
        endedAt('2026-10-15 23:59:00');
        endedAt('2026-10-14 08:00:00');

        $games = app(GameStats::class)->gamesPerDay(30);

        expect($games['2026-10-15'])->toBe(2)->and($games['2026-10-14'])->toBe(1)->and($games['2026-10-13'])->toBe(0);
    });

    it('leaves out games older than the window, and rooms that did not run to the end', function () {
        endedAt('2026-09-15 12:00:00');
        $partway = endedAt('2026-10-14 12:00:00');
        $partway->roomQuestions()->update(['revealed_at' => null]);
        Room::factory()->finished()->create(['finished_at' => '2026-10-14 12:00:00']);
        Room::factory()->playing()->create();

        expect(array_sum(app(GameStats::class)->gamesPerDay(30)))->toBe(0);
    });

    it('draws a line chart of them', function () {
        endedAt('2026-10-15 09:00:00');

        $chart = Livewire::test(GamesPerDayChart::class);

        $data = (fn () => $this->getData())->call($chart->instance());
        expect($data['datasets'][0]['data'])->toHaveCount(30)->and(array_sum($data['datasets'][0]['data']))->toBe(1)->and($data['labels'][29])->toBe('15 Oct');
    });
});

describe('most-played categories', function () {
    it('orders categories by how many of their questions were asked, leaving out those never asked', function () {
        $science = Category::factory()->create(['name' => ['en' => 'Science', 'ar' => 'العلوم']]);
        $history = Category::factory()->create(['name' => ['en' => 'History', 'ar' => 'التاريخ']]);
        Category::factory()->create(['name' => ['en' => 'Unused', 'ar' => 'غير مستخدم']]);
        $scienceQuestion = Question::factory()->create(['category_id' => $science->id]);
        $historyQuestion = Question::factory()->create(['category_id' => $history->id]);
        askedWith($historyQuestion, 1, 0);
        askedWith($scienceQuestion, 1, 0);
        askedWith($scienceQuestion, 1, 0);
        askedWith($historyQuestion, 0, 0, started: false); // scheduled but never shown: not played

        $categories = app(GameStats::class)->mostPlayedCategories();

        expect($categories->pluck('room_questions_count', 'id')->all())->toBe([$science->id => 2, $history->id => 1]);
    });

    it('draws a bar chart with the category names in English', function () {
        $science = Category::factory()->create(['name' => ['en' => 'Science', 'ar' => 'العلوم']]);
        askedWith(Question::factory()->create(['category_id' => $science->id]), 1, 0);

        $data = (fn () => $this->getData())->call(Livewire::test(MostPlayedCategoriesChart::class)->instance());

        expect($data['labels'])->toBe(['Science'])->and($data['datasets'][0]['data'])->toBe([1]);
    });
});

describe('questions most often answered wrong', function () {
    it('lists them by wrong answers with the share that were right', function () {
        $hard = Question::factory()->create();
        $medium = Question::factory()->create();
        $easy = Question::factory()->create();
        $untouched = Question::factory()->create();
        askedWith($hard, 1, 9);   // 10% correct
        askedWith($medium, 5, 5); // 50%
        askedWith($easy, 4, 0);   // never wrong: not listed

        $rows = app(GameStats::class)->hardestQuestionsQuery()->get();

        expect($rows->pluck('id')->all())->toBe([$hard->id, $medium->id])
            ->and(GameStats::percentCorrect($rows[0]->answers_count, $rows[0]->wrong_count))->toBe(10)
            ->and(GameStats::percentCorrect($rows[1]->answers_count, $rows[1]->wrong_count))->toBe(50)
            ->and($rows->pluck('id')->contains($untouched->id))->toBeFalse();
    });

    it('adds up the answers from every game the question was asked in', function () {
        $question = Question::factory()->create();
        askedWith($question, 2, 1);
        askedWith($question, 0, 2);

        $row = app(GameStats::class)->hardestQuestionsQuery()->first();

        expect($row->answers_count)->toBe(5)->and($row->wrong_count)->toBe(3)->and(GameStats::percentCorrect(5, 3))->toBe(40);
    });

    it('stops at ten', function () {
        foreach (range(1, 12) as $n) {
            askedWith(Question::factory()->create(), 0, $n);
        }

        expect(app(GameStats::class)->hardestQuestionsQuery(10)->get())->toHaveCount(10);
    });

    it('has no percentage for a question nobody answered', function () {
        expect(GameStats::percentCorrect(0, 0))->toBeNull()->and(GameStats::percentCorrect(8, 2))->toBe(75);
    });

    it('shows the table on the dashboard with the percentage', function () {
        $hard = Question::factory()->create();
        askedWith($hard, 1, 3);

        Livewire::test(HardestQuestions::class)->assertCanSeeTableRecords([$hard])->assertSee('25%')->assertSee('Wrong answers');
    });

    it('says so when there are no answers yet', function () {
        Livewire::test(HardestQuestions::class)->assertSee('No answers yet');
    });
});

describe('active rooms right now', function () {
    it('counts lobbies, games being played and connected players', function () {
        $lobby = Room::factory()->create();
        $playing = Room::factory()->playing()->create();
        RoomPlayer::factory()->for($lobby)->create(['left_at' => null]);
        RoomPlayer::factory()->for($playing)->count(2)->create(['left_at' => null]);
        RoomPlayer::factory()->for($playing)->create(['left_at' => now()]);

        expect(app(GameStats::class)->activeRooms())->toBe(['lobby' => 1, 'playing' => 1, 'players' => 3]);
    });

    it('leaves out finished rooms and rooms abandoned for hours', function () {
        Room::factory()->finished()->create();
        $old = Room::factory()->create(['created_at' => now()->subHours(7)]);
        RoomPlayer::factory()->for($old)->create(['left_at' => null]);
        Room::factory()->playing()->create(['created_at' => now()->subHours(5)]);

        expect(app(GameStats::class)->activeRooms())->toBe(['lobby' => 0, 'playing' => 1, 'players' => 0]);
    });

    it('shows the three numbers', function () {
        Room::factory()->create();
        Room::factory()->playing()->create(['status' => RoomStatus::Playing]);

        Livewire::test(ActiveRooms::class)->assertSee('Active rooms right now')->assertSee('In the lobby')->assertSee('Playing')->assertSee('Players connected');
    });
});

describe('the dashboard', function () {
    it('shows every widget', function () {
        $this->get('/admin')->assertOk()->assertSeeLivewire(ActiveRooms::class)->assertSeeLivewire(GamesPerDayChart::class)
            ->assertSeeLivewire(MostPlayedCategoriesChart::class)->assertSeeLivewire(HardestQuestions::class);
    });
});

describe('the question list', function () {
    it('shows how many times each question was played and the share answered correctly', function () {
        $played = Question::factory()->create();
        $never = Question::factory()->create();
        askedWith($played, 3, 1);
        askedWith($played, 1, 1);

        Livewire::test(ListQuestions::class)->assertCanSeeTableRecords([$played, $never])
            ->assertTableColumnStateSet('times_played', 2, $played)->assertTableColumnStateSet('percent_correct', '67%', $played)
            ->assertTableColumnStateSet('times_played', 0, $never)->assertTableColumnStateSet('percent_correct', '—', $never);
    });

    it('does not count a question that was scheduled but never shown as played', function () {
        $question = Question::factory()->create();
        askedWith($question, 0, 0, started: false);

        Livewire::test(ListQuestions::class)->assertTableColumnStateSet('times_played', 0, $question);
    });

    it('sorts by times played and by percent correct', function () {
        $popular = Question::factory()->create();
        $quiet = Question::factory()->create();
        $hard = Question::factory()->create();
        askedWith($popular, 5, 0);
        askedWith($popular, 5, 0);
        askedWith($popular, 5, 0);
        askedWith($quiet, 1, 1);
        askedWith($hard, 0, 4);
        askedWith($hard, 0, 4);

        Livewire::test(ListQuestions::class)->sortTable('times_played', 'desc')->assertCanSeeTableRecords([$popular, $hard, $quiet], inOrder: true);
        Livewire::test(ListQuestions::class)->sortTable('times_played', 'asc')->assertCanSeeTableRecords([$quiet, $hard, $popular], inOrder: true);
        Livewire::test(ListQuestions::class)->sortTable('percent_correct', 'asc')->assertCanSeeTableRecords([$hard, $quiet, $popular], inOrder: true);
        Livewire::test(ListQuestions::class)->sortTable('percent_correct', 'desc')->assertCanSeeTableRecords([$popular, $quiet, $hard], inOrder: true);
    });
});
