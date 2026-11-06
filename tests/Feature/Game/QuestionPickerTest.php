<?php

use App\Enums\Difficulty;
use App\Enums\RoomStatus;
use App\Events\GameStarted;
use App\Events\QuestionStarted;
use App\Game\GameEngine;
use App\Game\GameStartException;
use App\Game\QuestionPicker;
use App\Models\Category;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Room;
use App\Models\RoomQuestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // Only the game's own events: faking everything would also swallow Eloquent model events.
    Event::fake([GameStarted::class, QuestionStarted::class]);
    Queue::fake();
});

/** A started game's question ids, in position order. */
function askedIn(Room $room): array
{
    return $room->roomQuestions()->pluck('question_id')->all();
}

describe('which questions are picked', function () {
    it('picks exactly question_count distinct questions and stores them as positions 1..n', function () {
        $room = gameRoom(questions: 5);

        startGame($room);

        $rows = $room->roomQuestions()->get();
        expect($rows)->toHaveCount(5)
            ->and($rows->pluck('position')->all())->toBe([1, 2, 3, 4, 5])
            ->and($rows->pluck('question_id')->unique())->toHaveCount(5);
    });

    it('only picks active questions from the chosen categories', function () {
        $chosen = categoryWithQuestions(8);
        $other = categoryWithQuestions(8);
        Question::factory()->inactive()->withOptions()->count(5)->create(['category_id' => $chosen->id]);
        $room = gameRoom(questions: 5, category: $chosen);

        startGame($room);

        $categories = Question::whereIn('id', askedIn($room))->pluck('category_id')->unique()->all();
        expect($categories)->toBe([$chosen->id])
            ->and(Question::whereIn('id', askedIn($room))->where('is_active', false)->count())->toBe(0)
            ->and(Question::whereIn('id', askedIn($room))->where('category_id', $other->id)->count())->toBe(0);
    });

    it('spreads over several chosen categories', function () {
        $a = categoryWithQuestions(15);
        $b = categoryWithQuestions(15);
        $room = gameRoom(questions: 20, settings: ['categoryIds' => [$a->id, $b->id]], category: $a);

        startGame($room);

        expect(Question::whereIn('id', askedIn($room))->pluck('category_id')->unique()->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all());
    });

    it('matches the difficulty, and Mixed takes any', function () {
        $category = categoryWithQuestions(6, Difficulty::Easy);
        Question::factory()->count(6)->withOptions()->create(['category_id' => $category->id, 'difficulty' => Difficulty::Hard]);

        $hard = gameRoom(questions: 5, settings: ['difficulty' => Difficulty::Hard], category: $category);
        startGame($hard);
        expect(Question::whereIn('id', askedIn($hard))->pluck('difficulty')->unique()->all())->toBe([Difficulty::Hard]);

        $mixed = gameRoom(questions: 10, category: $category);
        startGame($mixed);
        expect(Question::whereIn('id', askedIn($mixed))->pluck('difficulty')->unique())->toHaveCount(2);
    });

    it('only picks questions fully translated in Arabic and English', function () {
        $category = categoryWithQuestions(6);
        Question::factory()->withOptions()->count(4)->create(['category_id' => $category->id, 'text' => ['en' => 'English only?']]);
        Question::factory()->withOptions()->count(4)->create(['category_id' => $category->id, 'text' => ['ar' => 'عربي فقط؟']]);
        $noArabicOption = Question::factory()->withOptions()->create(['category_id' => $category->id]);
        QuestionOption::factory()->for($noArabicOption)->create(['text' => ['en' => 'No Arabic']]);
        $room = gameRoom(questions: 5, category: $category);

        startGame($room);

        foreach (Question::with('options')->whereIn('id', askedIn($room))->get() as $question) {
            expect($question->isTranslatedIn(['ar', 'en']))->toBeTrue();
        }
        expect(askedIn($room))->not->toContain($noArabicOption->id);
    });

    it('refuses to start when there are not enough playable questions, leaving the lobby untouched', function () {
        $category = categoryWithQuestions(4);
        $room = gameRoom(questions: 10, category: $category);

        expect(fn () => startGame($room))->toThrow(GameStartException::class, 'Only 4 playable questions');

        expect($room->fresh()->status)->toBe(RoomStatus::Lobby)->and(RoomQuestion::count())->toBe(0);
    });

    it('is all or nothing: a failure while storing leaves no questions and no started game', function () {
        $room = gameRoom(questions: 5);
        RoomQuestion::creating(function (RoomQuestion $row) {
            if ($row->position === 3) {
                throw new RuntimeException('boom');
            }
        });

        expect(fn () => startGame($room))->toThrow(RuntimeException::class);

        expect(RoomQuestion::count())->toBe(0)->and($room->fresh()->status)->toBe(RoomStatus::Lobby);
    });
});

describe('avoiding what the host just asked', function () {
    function playedRoom(User $host, array $questionIds): Room
    {
        $room = Room::factory()->for($host, 'host')->finished()->create();
        foreach ($questionIds as $position => $id) {
            RoomQuestion::factory()->create(['room_id' => $room->id, 'question_id' => $id, 'position' => $position + 1]);
        }

        return $room;
    }

    it('skips the host\'s last three rooms when there are enough other questions', function () {
        $category = categoryWithQuestions(30);
        $all = Question::pluck('id')->all();
        $host = User::factory()->create();
        playedRoom($host, array_slice($all, 0, 5));
        playedRoom($host, array_slice($all, 5, 5));
        playedRoom($host, array_slice($all, 10, 5));
        $room = gameRoom(questions: 10, host: $host, category: $category);

        startGame($room);

        expect(array_intersect(askedIn($room), array_slice($all, 0, 15)))->toBe([])
            ->and(askedIn($room))->toHaveCount(10);
    });

    it('does not count a fourth room back, other hosts\' rooms, or rooms that never asked anything', function () {
        $category = categoryWithQuestions(15);
        $all = Question::pluck('id')->all();
        $host = User::factory()->create();
        playedRoom($host, array_slice($all, 0, 5));          // 4th back: allowed again
        playedRoom($host, array_slice($all, 5, 3));
        playedRoom($host, array_slice($all, 8, 3));
        playedRoom($host, array_slice($all, 11, 4));
        Room::factory()->for($host, 'host')->finished()->create(); // a room that never asked anything does not use up a slot
        playedRoom(User::factory()->create(), array_slice($all, 0, 15)); // someone else's
        $picker = app(QuestionPicker::class);
        $room = gameRoom(questions: 5, host: $host, category: $category);

        expect($picker->recentlyAsked($room))->toEqualCanonicalizing(array_slice($all, 5, 10));
    });

    it('tops up with recent questions when there are not enough others', function () {
        $category = categoryWithQuestions(12);
        $all = Question::pluck('id')->all();
        $host = User::factory()->create();
        playedRoom($host, array_slice($all, 0, 8));
        $room = gameRoom(questions: 10, host: $host, category: $category);

        startGame($room);

        $asked = askedIn($room);
        expect($asked)->toHaveCount(10)->and(collect($asked)->unique())->toHaveCount(10)
            ->and(array_diff(array_slice($all, 8, 4), $asked))->toBe([]); // every fresh question is in
    });

    it('never counts the room being started', function () {
        $category = categoryWithQuestions(8);
        $host = User::factory()->create();
        $room = gameRoom(questions: 5, host: $host, category: $category);

        expect(app(QuestionPicker::class)->recentlyAsked($room))->toBe([]);
    });
});

describe('the option order', function () {
    it('is a fixed shuffle of the question\'s own options, stored with the question', function () {
        $room = gameRoom(questions: 10);

        startGame($room);

        foreach ($room->roomQuestions()->with('question.options')->get() as $roomQuestion) {
            $ids = $roomQuestion->question->options->pluck('id')->sort()->values()->all();
            expect(collect($roomQuestion->option_order)->sort()->values()->all())->toBe($ids);
        }
    });

    it('is shuffled, not the stored sort order', function () {
        $room = gameRoom(questions: 10);

        startGame($room);

        $shuffled = $room->roomQuestions()->with('question.options')->get()
            ->filter(fn ($rq) => $rq->option_order !== $rq->question->options->pluck('id')->all());
        expect($shuffled->count())->toBeGreaterThan(0);
    });

    it('does not change once stored', function () {
        $room = gameRoom(questions: 3);
        startGame($room);
        $stored = $room->roomQuestions()->pluck('option_order', 'position')->all();

        // reads, reveals and reloads never reshuffle
        app(GameEngine::class)->tick($room);
        $again = $room->fresh()->roomQuestions()->pluck('option_order', 'position')->all();

        expect($again)->toBe($stored);
    });
});

/** A category with 12 playable questions of each difficulty. */
function mixedCategory(): Category
{
    return tap(Category::factory()->create(), function (Category $category) {
        foreach (Difficulty::cases() as $difficulty) {
            Question::factory()->count(12)->withOptions()->create(['category_id' => $category->id, 'difficulty' => $difficulty]);
        }
    });
}

/** The tier of each of the room's questions, in position order: 0 easy, 1 medium, 2 hard. */
function tiersOf(Room $room): array
{
    return $room->roomQuestions()->with('question')->get()
        ->map(fn (RoomQuestion $row) => array_search($row->question->difficulty, Difficulty::cases(), true))->all();
}

function expectClimbing(array $tiers): void
{
    $sorted = $tiers;
    sort($sorted);

    expect($tiers)->toBe($sorted);
}

describe('the order of a Mixed game: easy first, then medium, then hard', function () {
    it('climbs through the tiers over the whole of a Classic game', function () {
        $room = gameRoom(questions: 20, category: mixedCategory());

        startGame($room);

        expectClimbing(tiersOf($room));
        expect(array_unique(tiersOf($room)))->toHaveCount(3);
    });

    it('is random inside each tier', function () {
        $room = gameRoom(questions: 20, category: mixedCategory());

        $ids = app(QuestionPicker::class)->pickFrom($room, $room->settings->categoryIds, 36)->where('difficulty', Difficulty::Easy)->pluck('id')->all();
        $sorted = $ids;
        sort($sorted);

        // 12 easy questions in id order by chance: one in 479 million.
        expect($ids)->toHaveCount(12)->not->toBe($sorted);
    });

    it('starts again from easy in each batch of a Vote game', function () {
        $room = voteRoom(questions: 6, every: 3, categories: [mixedCategory(), mixedCategory(), mixedCategory()]);

        playToVote($room);
        app(GameEngine::class)->closeVote($room, 1, force: true);

        $tiers = tiersOf($room);
        expect($tiers)->toHaveCount(6);
        expectClimbing(array_slice($tiers, 0, 3));
        expectClimbing(array_slice($tiers, 3, 3));
    });

    it('keeps the order when a short category\'s batch is filled from the other pool categories', function () {
        $short = Category::factory()->create();
        Question::factory()->count(2)->withOptions()->create(['category_id' => $short->id, 'difficulty' => Difficulty::Hard]);
        $room = voteRoom(questions: 6, every: 3, categories: [mixedCategory(), $short, mixedCategory()]);

        expect(votes()->drawBatch($room, $short->id, 6))->toBe(6);

        expectClimbing(tiersOf($room));
    });

    it('leaves a single-difficulty game in random order', function () {
        $room = gameRoom(questions: 12, category: categoryWithQuestions(14, Difficulty::Hard), settings: ['difficulty' => Difficulty::Hard]);

        $ids = app(QuestionPicker::class)->pick($room)->pluck('id')->all();
        $sorted = $ids;
        sort($sorted);

        expect($ids)->toHaveCount(12)->not->toBe($sorted);
    });

    it('does not change which questions are preferred: unseen first', function () {
        $category = mixedCategory();
        $room = gameRoom(players: 1, questions: 3, category: $category);
        $player = $room->players()->first();
        $seen = Question::query()->where('category_id', $category->id)->where('difficulty', Difficulty::Easy)->take(11)->pluck('id');
        foreach ($seen as $id) {
            DB::table('question_seen')->insert(['question_id' => $id, 'guest_token' => $player->guest_token, 'room_id' => $room->id, 'seen_at' => now()]);
        }

        expect(app(QuestionPicker::class)->pick($room)->pluck('id')->intersect($seen))->toBeEmpty();
    });
});
