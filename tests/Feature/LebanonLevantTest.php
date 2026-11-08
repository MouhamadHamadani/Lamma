<?php

use App\Enums\Difficulty;
use App\Events\GameStarted;
use App\Events\QuestionStarted;
use App\Game\QuestionPool;
use App\Game\RoomSettings;
use App\Livewire\Host\CreateRoom;
use App\Models\Category;
use App\Models\Question;
use App\Models\User;
use App\Support\CategoryStyle;
use Database\Seeders\ContentSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/** Phase 15: the Lebanon & Levant subcategory, seeded for real, seen from the pool, both game modes, the create-room screen and the landing page. */
beforeEach(function () {
    (new ContentSeeder)->run();
    $this->arab = Category::where('slug', 'arab-world')->firstOrFail();
    $this->lebanon = Category::where('slug', 'lebanon-levant')->firstOrFail();
});

function lebanonPool(array $ids, ?Difficulty $difficulty = null): int
{
    return app(QuestionPool::class)->available(new RoomSettings(categoryIds: $ids, difficulty: $difficulty));
}

it('is a subcategory of The Arab World with its name in both languages and the cedar-leaf icon', function () {
    expect($this->lebanon->parent_id)->toBe($this->arab->id)
        ->and($this->lebanon->getTranslation('name', 'en'))->toBe('Lebanon & Levant')
        ->and($this->lebanon->getTranslation('name', 'ar'))->toBe('لبنان وبلاد الشام')
        ->and($this->lebanon->fullName('en'))->toBe('The Arab World · Lebanon & Levant')
        ->and($this->lebanon->fullName('ar'))->toBe('العالم العربي · لبنان وبلاد الشام')
        ->and(CategoryStyle::icon('lebanon-levant'))->toBe('leaf')
        ->and($this->lebanon->tintSlug())->toBe('arab-world');
});

it('holds its 120 questions, counted under The Arab World and on their own', function () {
    $own = $this->arab->questions()->count();

    expect(lebanonPool([$this->lebanon->id]))->toBe(120)
        ->and(lebanonPool([$this->lebanon->id], Difficulty::Easy))->toBe(30)
        ->and(lebanonPool([$this->lebanon->id], Difficulty::Medium))->toBe(45)
        ->and(lebanonPool([$this->lebanon->id], Difficulty::Hard))->toBe(45)
        ->and(lebanonPool([$this->arab->id]))->toBe($own + 120)
        ->and(lebanonPool([$this->lebanon->id, $this->arab->id]))->toBe($own + 120);   // already inside the whole category: counted once
});

it('can be played in Classic mode, and then every question comes from it', function () {
    Event::fake([GameStarted::class, QuestionStarted::class]);
    Queue::fake();
    $room = gameRoom(questions: 10, category: $this->lebanon);

    startGame($room);

    $categories = Question::whereIn('id', $room->roomQuestions()->pluck('question_id'))->pluck('category_id')->unique()->all();
    expect($room->roomQuestions()->count())->toBe(10)->and($categories)->toBe([$this->lebanon->id]);
});

it('can be played in Vote mode: the first batch comes from The Arab World, which includes it', function () {
    Event::fake([GameStarted::class, QuestionStarted::class]);
    Queue::fake();
    // Only Lebanon & Levant is left in The Arab World's tree, so the first batch can only be its questions.
    Question::where('category_id', $this->arab->id)->update(['is_active' => false]);
    $room = voteRoom(questions: 10, every: 5, categories: [$this->arab, Category::where('slug', 'science')->first(), Category::where('slug', 'geography')->first()]);

    startGame($room);

    $first = $room->roomQuestions()->orderBy('position')->get();
    expect($first)->toHaveCount(5)
        ->and($first->every(fn ($roomQuestion) => topCategoryOf($roomQuestion) === $this->arab->id))->toBeTrue()
        ->and(Question::whereIn('id', $first->pluck('question_id'))->pluck('category_id')->unique()->all())->toBe([$this->lebanon->id]);
});

it('adds nothing when the seeder runs again, and leaves an admin\'s edit alone', function () {
    $edited = Question::where('category_id', $this->lebanon->id)->firstOrFail();
    $edited->update(['is_active' => false]);
    $removed = Question::where('category_id', $this->lebanon->id)->where('id', '!=', $edited->id)->firstOrFail();
    $removed->delete();
    $count = Question::withTrashed()->count();

    (new ContentSeeder)->run();

    expect(Question::withTrashed()->count())->toBe($count)
        ->and($edited->fresh()->is_active)->toBeFalse()
        ->and(Question::withTrashed()->find($removed->id)->trashed())->toBeTrue();
});

describe('on the create-room screen', function () {
    beforeEach(fn () => $this->host = User::factory()->create());

    it('shows the chip in English under The Arab World', function () {
        app()->setLocale('en');

        Livewire::actingAs($this->host)->test(CreateRoom::class)
            ->call('toggleExpanded', $this->arab->id)
            ->assertSeeHtml('data-test="subcategory-lebanon-levant"')->assertSee('Lebanon & Levant')
            ->call('toggleSubcategory', $this->lebanon->id)->assertSet('categoryIds', [$this->lebanon->id])->assertSee('The Arab World: Lebanon & Levant');
    });

    it('shows the chip in Arabic under العالم العربي', function () {
        $this->withSession(['locale' => 'ar']);
        app()->setLocale('ar');

        Livewire::actingAs($this->host)->test(CreateRoom::class)
            ->call('toggleExpanded', $this->arab->id)
            ->assertSeeHtml('data-test="subcategory-lebanon-levant"')->assertSee('لبنان وبلاد الشام')
            ->call('toggleSubcategory', $this->lebanon->id)->assertSee('العالم العربي: لبنان وبلاد الشام');
    });
});

describe('on the landing page', function () {
    it('still loads with the seeded categories, in both languages, and shows only top-level ones', function () {
        $this->withSession(['locale' => 'en'])->get('/')->assertOk()->assertSee('id="categories"', false)->assertSee('General Knowledge')->assertDontSee('Lebanon & Levant');

        $this->withSession(['locale' => 'ar'])->get('/')->assertOk()->assertSee('معلومات عامة')->assertDontSee('لبنان وبلاد الشام');
    });
});
