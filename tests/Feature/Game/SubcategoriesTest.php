<?php

use App\Enums\Difficulty;
use App\Enums\HostScreenLocale;
use App\Events\GameStarted;
use App\Events\QuestionStarted;
use App\Game\CategorySelection;
use App\Game\QuestionPool;
use App\Game\RoomSettings;
use App\Livewire\Host\HostGame;
use App\Models\Category;
use App\Models\Question;
use App\Support\CategoryStyle;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/** A top-level category and its subcategories, each holding $each playable questions; returns [parent, [child, child, ...]]. */
function categoryTree(array $children = ['Football', 'Basketball'], int $each = 4, int $direct = 3): array
{
    $parent = categoryWithQuestions($direct, category: ['name' => ['en' => 'Sports', 'ar' => 'رياضة'], 'slug' => 'sports', 'sort_order' => 5]);
    $subs = [];
    foreach ($children as $order => $name) {
        $subs[] = categoryWithQuestions($each, category: ['name' => ['en' => $name, 'ar' => 'ع '.$name], 'slug' => str($name)->slug()->toString(), 'parent_id' => $parent->id, 'sort_order' => $order + 1]);
    }

    return [$parent, $subs];
}

function poolCount(array $ids, ?Difficulty $difficulty = null): int
{
    return app(QuestionPool::class)->available(new RoomSettings(categoryIds: $ids, difficulty: $difficulty));
}

describe('the one-level hierarchy', function () {
    it('keeps every existing category top-level', function () {
        $category = Category::factory()->create();

        expect($category->fresh()->parent_id)->toBeNull()->and($category->isSubcategory())->toBeFalse()
            ->and(Category::topLevel()->count())->toBe(1);
    });

    it('lets a subcategory sit under a top-level category, with the same fields', function () {
        $parent = Category::factory()->create();

        $child = Category::factory()->under($parent)->create(['name' => ['ar' => 'كرة القدم', 'en' => 'Football'], 'icon' => 'x', 'is_active' => false, 'sort_order' => 3]);

        expect($child->fresh()->parent->is($parent))->toBeTrue()->and($child->isSubcategory())->toBeTrue()
            ->and($child->getTranslation('name', 'ar'))->toBe('كرة القدم')->and($child->is_active)->toBeFalse()
            ->and($parent->children)->toHaveCount(1);
    });

    it('refuses a third level: a subcategory cannot have a subcategory', function () {
        $parent = Category::factory()->create();
        $child = Category::factory()->under($parent)->create();

        expect(fn () => Category::factory()->under($child)->create())->toThrow(ValidationException::class, 'top-level');
        expect(fn () => Category::factory()->create(['parent_id' => 99999]))->toThrow(ValidationException::class);
    });

    it('refuses a category that is its own parent', function () {
        $category = Category::factory()->create();

        expect(fn () => $category->update(['parent_id' => $category->id]))->toThrow(ValidationException::class, 'own parent');
    });

    it('refuses to move a category that has subcategories under another one', function () {
        $a = Category::factory()->create();
        Category::factory()->under($a)->create();
        $b = Category::factory()->create();

        expect(fn () => $a->update(['parent_id' => $b->id]))->toThrow(ValidationException::class, 'cannot become a subcategory');
    });

    it('lets a childless top-level category become a subcategory, and back', function () {
        $a = Category::factory()->create();
        $b = Category::factory()->create();

        $b->update(['parent_id' => $a->id]);
        expect($b->fresh()->isSubcategory())->toBeTrue();

        $b->update(['parent_id' => null]);
        expect($b->fresh()->isSubcategory())->toBeFalse();
    });

    it('explains the problem with the same message the admin form shows', function () {
        $parent = Category::factory()->create();
        $child = Category::factory()->under($parent)->create();

        expect(Category::parentProblem(null))->toBeNull()
            ->and(Category::parentProblem($parent->id))->toBeNull()
            ->and(Category::parentProblem($child->id))->toContain('top-level');
    });

    it('does not let a parent with subcategories be deleted, so no question is lost by accident', function () {
        [$parent] = categoryTree();

        expect(fn () => $parent->delete())->toThrow(QueryException::class);
        expect(Category::count())->toBe(3);
    });
});

describe('which questions a selection includes', function () {
    it('takes all of a top-level category: its own questions and every subcategory\'s', function () {
        [$parent] = categoryTree(each: 4, direct: 3);

        expect(poolCount([$parent->id]))->toBe(3 + 4 + 4);
    });

    it('takes only that subcategory when only a subcategory is chosen', function () {
        [$parent, [$football, $basketball]] = categoryTree(each: 4, direct: 3);

        expect(poolCount([$football->id]))->toBe(4)
            ->and(poolCount([$football->id, $basketball->id]))->toBe(8)
            ->and(poolCount([$football->id, $parent->id]))->toBe(11); // the whole parent already includes it: counted once
    });

    it('adds a subcategory to other categories', function () {
        [, [$football]] = categoryTree(each: 4, direct: 3);
        $science = categoryWithQuestions(5);

        expect(poolCount([$football->id, $science->id]))->toBe(9);
    });

    it('leaves out an inactive subcategory, and everything under an inactive parent', function () {
        [$parent, [$football, $basketball]] = categoryTree(each: 4, direct: 3);
        $basketball->update(['is_active' => false]);

        expect(poolCount([$parent->id]))->toBe(3 + 4)->and(poolCount([$basketball->id]))->toBe(0);

        $parent->update(['is_active' => false]);

        expect(poolCount([$parent->id]))->toBe(0)->and(poolCount([$football->id]))->toBe(0);
    });

    it('applies the difficulty to the subcategory\'s own questions', function () {
        [, [$football]] = categoryTree(each: 0);
        Question::factory()->count(3)->withOptions()->create(['category_id' => $football->id, 'difficulty' => Difficulty::Hard]);
        Question::factory()->count(2)->withOptions()->create(['category_id' => $football->id, 'difficulty' => Difficulty::Easy]);

        expect(poolCount([$football->id], Difficulty::Hard))->toBe(3)->and(poolCount([$football->id], Difficulty::Easy))->toBe(2)->and(poolCount([$football->id]))->toBe(5);
    });

    it('starts a game from a subcategory and asks only its questions', function () {
        Event::fake([GameStarted::class, QuestionStarted::class]);
        Queue::fake();
        [, [$football]] = categoryTree(each: 8, direct: 8);
        $room = gameRoom(questions: 5, category: $football);

        startGame($room);

        expect(Question::whereIn('id', $room->roomQuestions()->pluck('question_id'))->pluck('category_id')->unique()->all())->toBe([$football->id]);
    });
});

describe('reading a selection', function () {
    it('tells whole, partial and off', function () {
        [$parent, [$football, $basketball]] = categoryTree();
        $parent->load('children');

        expect(CategorySelection::state($parent, [$parent->id]))->toBe(CategorySelection::ALL)
            ->and(CategorySelection::state($parent, [$football->id]))->toBe(CategorySelection::PARTIAL)
            ->and(CategorySelection::state($parent, [$football->id, $basketball->id]))->toBe(CategorySelection::PARTIAL)
            ->and(CategorySelection::state($parent, []))->toBe(CategorySelection::OFF);
    });

    it('drops subcategory ids that a chosen parent already covers', function () {
        [$parent, [$football, $basketball]] = categoryTree();
        $science = categoryWithQuestions(2);

        expect(CategorySelection::normalize([$football->id, $parent->id, $science->id, $football->id]))->toEqualCanonicalizing([$parent->id, $science->id])
            ->and(CategorySelection::normalize([$football->id, $basketball->id]))->toEqualCanonicalizing([$football->id, $basketball->id]);
    });

    it('describes it compactly, in either language', function () {
        [$parent, [$football, $basketball]] = categoryTree(['Football', 'Basketball', 'Rugby']);
        $science = categoryWithQuestions(2, category: ['name' => ['en' => 'Science', 'ar' => 'علوم'], 'sort_order' => 9]);

        expect(CategorySelection::label([$football->id, $basketball->id, $science->id], 'en'))->toBe('Sports: Football, Basketball · Science')
            ->and(CategorySelection::label([$parent->id, $science->id], 'en'))->toBe('Sports · Science')
            ->and(CategorySelection::label([$parent->id, $football->id], 'en'))->toBe('Sports')
            ->and(CategorySelection::label([$football->id], 'ar'))->toBe('رياضة: ع Football');
    });
});

describe('showing the subcategory', function () {
    it('names it "Sports · Football" in both languages and wears the parent\'s tint', function () {
        [$parent, [$football]] = categoryTree();
        $football->load('parent');

        expect($football->fullName('en'))->toBe('Sports · Football')->and($football->fullName('ar'))->toBe('رياضة · ع Football')
            ->and($parent->fullName('en'))->toBe('Sports')
            ->and(CategoryStyle::tintName($football->tintSlug()))->toBe(CategoryStyle::tintName('sports'))
            ->and($football->tintSlug())->toBe('sports');
    });

    it('is in the chip of the host question screen and the question payload', function () {
        Event::fake([GameStarted::class, QuestionStarted::class]);
        Queue::fake();
        [, [$football]] = categoryTree(each: 8, direct: 8);
        $room = gameRoom(questions: 3, category: $football, settings: ['hostScreenLocale' => HostScreenLocale::En]);
        startGame($room);

        Livewire::actingAs($room->host)->test(HostGame::class, ['room' => $room])->assertSeeHtml('data-test="category"')->assertSee('Sports · Football');

        Event::assertDispatched(QuestionStarted::class, fn (QuestionStarted $event) => $event->question['category']['name'] === ['ar' => 'رياضة · ع Football', 'en' => 'Sports · Football']);
    });

    it('has an icon for every sports and general knowledge subcategory', function (string $slug) {
        expect(CategoryStyle::icon($slug))->not->toBe('sparkles');
    })->with(['football', 'basketball', 'rugby', 'tennis', 'motorsport', 'olympics', 'combat-sports', 'everyday-life', 'words-language', 'inventions', 'human-body', 'landmarks', 'arts-music']);
});
