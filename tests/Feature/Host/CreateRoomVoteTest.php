<?php

use App\Enums\Difficulty;
use App\Enums\GameMode;
use App\Enums\RoomStatus;
use App\Game\RoomManager;
use App\Livewire\Host\CreateRoom;
use App\Models\Category;
use App\Models\Question;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->host = User::factory()->create(['preferred_locale' => 'en']);
});

function votePage(): Testable
{
    return Livewire::actingAs(test()->host)->test(CreateRoom::class)->set('mode', 'vote');
}

/** @return list<Category> $count top-level categories with $questions playable questions each */
function voteCategories(int $count = 3, int $questions = 10): array
{
    return collect(range(1, $count))->map(fn () => categoryWithQuestions($questions))->all();
}

describe('Classic stays as it was', function () {
    it('starts in Classic mode and creates a Classic room with no vote setup', function () {
        $category = categoryWithQuestions(12);

        Livewire::actingAs($this->host)->test(CreateRoom::class)
            ->assertSet('mode', 'classic')
            ->call('toggleCategory', $category->id)
            ->call('create')
            ->assertHasNoErrors();

        $room = Room::sole();
        expect($room->game_mode)->toBe(GameMode::Classic)
            ->and($room->vote_every)->toBeNull()
            ->and($room->first_category_id)->toBeNull()
            ->and($room->pool()->count())->toBe(0)
            ->and($room->settings->categoryIds)->toBe([$category->id]);
    });

    it('shows the mode switch and the classic categories, not the vote setup', function () {
        voteCategories(1);

        Livewire::actingAs($this->host)->test(CreateRoom::class)
            ->assertSeeHtml('data-test="mode"')
            ->assertDontSeeHtml('data-test="vote-setup"')
            ->assertSee('Pick one or more');
    });

    it('keeps the classic choice when switching to Vote and back', function () {
        [$a] = voteCategories(1);

        Livewire::actingAs($this->host)->test(CreateRoom::class)
            ->call('toggleCategory', $a->id)
            ->set('mode', 'vote')->set('mode', 'classic')
            ->assertSet('categoryIds', [$a->id]);
    });
});

describe('the Vote setup', function () {
    it('shows the first category, the pool, vote every and the helper text instead of the classic categories', function () {
        voteCategories(3);

        votePage()
            ->assertSeeHtml('data-test="vote-setup"')
            ->assertSeeHtml('data-test="vote-every"')
            ->assertSee('After every few questions, the players vote on the category of the next ones.')
            ->assertDontSee('Pick one or more')
            ->assertSee('Pick the first category.');
    });

    it('puts the first category in the pool and keeps it there', function () {
        [$a, $b] = voteCategories(2);

        votePage()
            ->call('chooseFirst', $a->id)->assertSet('firstCategoryId', $a->id)->assertSet('poolIds', [$a->id])
            ->call('togglePool', $a->id)->assertSet('poolIds', [$a->id])
            ->call('togglePool', $b->id)->assertSet('poolIds', [$a->id, $b->id])
            ->call('togglePool', $b->id)->assertSet('poolIds', [$a->id]);
    });

    it('only offers top-level active categories, and ignores the others', function () {
        $parent = categoryWithQuestions(10);
        $sub = Category::factory()->create(['parent_id' => $parent->id]);
        $inactive = Category::factory()->inactive()->create();

        votePage()
            ->call('chooseFirst', $sub->id)->call('chooseFirst', $inactive->id)->assertSet('firstCategoryId', null)
            ->call('togglePool', $sub->id)->call('togglePool', 99999)->assertSet('poolIds', []);
    });

    it('needs at least three categories in the pool, the first one included', function () {
        [$a, $b] = voteCategories(2);

        $page = votePage()->call('chooseFirst', $a->id)->call('togglePool', $b->id)
            ->assertSee('Pick at least 3 categories to vote between.')
            ->assertSet('canCreate', false);

        $page->call('create')->assertHasErrors('poolIds');
        expect(Room::count())->toBe(0);
    });

    it('counts the first category in the pool even when the pool list lacks it', function () {
        [$a, $b, $c] = voteCategories(3);

        votePage()->set('firstCategoryId', $a->id)->set('poolIds', [$b->id, $c->id])->set('questionCount', 10)
            ->assertSet('canCreate', true)
            ->call('create')->assertHasNoErrors();

        expect(Room::sole()->pool()->pluck('categories.id')->sort()->values()->all())->toBe([$a->id, $b->id, $c->id]);
    });

    it('needs vote every to be smaller than the number of questions', function () {
        [$a, $b, $c] = voteCategories(3, questions: 12);

        $page = votePage()->call('chooseFirst', $a->id)->call('togglePool', $b->id)->call('togglePool', $c->id)
            ->set('questionCount', 10)->set('voteEvery', 10)
            ->assertSee('Vote every 10 questions needs a game longer than 10 questions.')
            ->assertSet('canCreate', false);

        $page->call('create')->assertHasErrors('poolIds');
        $page->set('voteEvery', 5)->assertSet('canCreate', true);
    });

    it('refuses a vote every the host cannot pick', function () {
        [$a, $b, $c] = voteCategories(3);

        votePage()->call('chooseFirst', $a->id)->call('togglePool', $b->id)->call('togglePool', $c->id)->set('voteEvery', 4)
            ->call('create')->assertHasErrors('voteEvery');
    });

    it('names each pool category that cannot fill a batch, with how many questions it has, and blocks creation', function () {
        [$a, $b] = voteCategories(2, questions: 10);
        $thin = categoryWithQuestions(3, category: ['name' => ['en' => 'Thin Topic', 'ar' => 'موضوع قليل']]);

        votePage()->call('chooseFirst', $a->id)->call('togglePool', $b->id)->call('togglePool', $thin->id)->set('voteEvery', 5)
            ->assertSeeHtml('data-test="vote-shortfalls"')
            ->assertSee('Thin Topic')
            ->assertSeeHtml('<bdi dir="ltr">3</bdi>')
            ->assertSet('canCreate', false)
            ->call('create')->assertHasErrors('poolIds');

        expect(Room::count())->toBe(0);
    });

    it('checks the first category can supply the first batch', function () {
        [$b, $c] = voteCategories(2);
        $first = categoryWithQuestions(4);

        votePage()->call('chooseFirst', $first->id)->call('togglePool', $b->id)->call('togglePool', $c->id)->set('voteEvery', 5)
            ->assertSet('shortfalls', [$first->id => 4])
            ->assertSet('canCreate', false);
    });

    it('counts availability at the chosen difficulty', function () {
        [$a, $b] = voteCategories(2);
        $mixed = Category::factory()->create();
        Question::factory()->count(4)->withOptions()->create(['category_id' => $mixed->id, 'difficulty' => Difficulty::Hard]);
        Question::factory()->count(8)->withOptions()->create(['category_id' => $mixed->id, 'difficulty' => Difficulty::Easy]);

        $page = votePage()->call('chooseFirst', $a->id)->call('togglePool', $b->id)->call('togglePool', $mixed->id)->set('voteEvery', 5);

        $page->set('difficulty', 'mixed')->assertSet('shortfalls', fn (array $short) => ! isset($short[$mixed->id]));
        $page->set('difficulty', 'easy')->assertSet('shortfalls', fn (array $short) => ! isset($short[$mixed->id]));
        $page->set('difficulty', 'hard')->assertSet('shortfalls', fn (array $short) => ($short[$mixed->id] ?? null) === 4);
    });

    it('counts the subcategories of a pool category', function () {
        [$a, $b] = voteCategories(2);
        $parent = Category::factory()->create();
        categoryWithQuestions(6, category: ['parent_id' => $parent->id]);

        votePage()->call('chooseFirst', $a->id)->call('togglePool', $b->id)->call('togglePool', $parent->id)->set('voteEvery', 5)
            ->assertSet('shortfalls', []);
    });

    it('needs enough questions in the whole pool for the game', function () {
        [$a, $b, $c] = voteCategories(3, questions: 5);

        votePage()->call('chooseFirst', $a->id)->call('togglePool', $b->id)->call('togglePool', $c->id)
            ->set('voteEvery', 5)->set('questionCount', 20)
            ->assertSet('shortfalls', [])
            ->assertSeeHtml('data-test="not-enough-questions"')
            ->assertSet('canCreate', false);
    });

    it('summarises the mode, first category, pool and the number of votes', function () {
        [$a, $b, $c] = voteCategories(3, questions: 10);
        $a->update(['name' => ['en' => 'Opening Act', 'ar' => 'البداية']]);

        votePage()->call('chooseFirst', $a->id)->call('togglePool', $b->id)->call('togglePool', $c->id)
            ->set('questionCount', 20)->set('voteEvery', 5)
            ->assertSee('Opening Act')
            ->assertSee('Vote · 3 categories')
            ->assertSeeHtml('Every <bdi dir="ltr">5</bdi> questions · <bdi dir="ltr">3</bdi> votes')
            ->set('questionCount', 10)
            ->assertSeeHtml('Every <bdi dir="ltr">5</bdi> questions · 1 vote');
    });

    it('creates a Vote room with its first category, pool and vote every, and no classic categories', function () {
        [$a, $b, $c] = voteCategories(3, questions: 10);

        votePage()->call('chooseFirst', $a->id)->call('togglePool', $b->id)->call('togglePool', $c->id)
            ->set('questionCount', 15)->set('voteEvery', 3)
            ->call('create')
            ->assertHasNoErrors()
            ->assertRedirect();

        $room = Room::sole();
        expect($room->game_mode)->toBe(GameMode::Vote)
            ->and($room->first_category_id)->toBe($a->id)
            ->and($room->vote_every)->toBe(3)
            ->and($room->pool()->pluck('categories.id')->sort()->values()->all())->toBe([$a->id, $b->id, $c->id])
            ->and($room->settings->categoryIds)->toBe([])
            ->and($room->settings->questionCount)->toBe(15);
    });

    it('is translated in Arabic', function () {
        voteCategories(3);
        $this->host->update(['preferred_locale' => 'ar']);

        $this->actingAs($this->host)->withSession(['locale' => 'ar'])->get(route('rooms.create'))
            ->assertSee('كلاسيكي')->assertSee('تصويت')->assertSee('dir="rtl"', false);
    });
});

describe('Play again', function () {
    it('copies the mode, first category, pool and vote every to the new room', function () {
        Event::fake();
        [$a, $b, $c] = voteCategories(3, questions: 10);
        $old = Room::factory()->vote(every: 3)->finished()->create([
            'host_id' => $this->host->id, 'first_category_id' => $b->id, 'settings' => ['question_count' => 10],
        ]);
        $old->pool()->attach([$a->id, $b->id, $c->id]);
        RoomPlayer::factory()->for($old)->create(['left_at' => null]);

        $new = app(RoomManager::class)->playAgain($old, $this->host);

        expect($new->status)->toBe(RoomStatus::Lobby)
            ->and($new->game_mode)->toBe(GameMode::Vote)
            ->and($new->first_category_id)->toBe($b->id)
            ->and($new->vote_every)->toBe(3)
            ->and($new->pool()->pluck('categories.id')->sort()->values()->all())->toBe([$a->id, $b->id, $c->id]);
    });
});

describe('the hint at exactly three categories', function () {
    it('suggests a fourth without asking for it: three can still be created', function () {
        $categories = voteCategories(4, 12);
        $page = votePage()->call('chooseFirst', $categories[0]->id)->call('togglePool', $categories[1]->id)->assertDontSeeHtml('data-test="vote-hint"');

        $page->call('togglePool', $categories[2]->id)
            ->assertSeeHtml('data-test="vote-hint"')
            ->assertSeeHtml('Pick <bdi dir="ltr">4</bdi> or more so every vote has a real choice.')
            ->assertDontSeeHtml('data-test="vote-problem"');
        expect($page->instance()->canCreate)->toBeTrue();

        $page->call('togglePool', $categories[3]->id)->assertDontSeeHtml('data-test="vote-hint"');

        $page->call('togglePool', $categories[3]->id)->call('create')->assertHasNoErrors();
        expect(Room::sole()->pool()->count())->toBe(3);
    });

    it('is in Arabic too', function () {
        app()->setLocale('ar');
        $categories = voteCategories(3, 12);

        votePage()->call('chooseFirst', $categories[0]->id)->call('togglePool', $categories[1]->id)->call('togglePool', $categories[2]->id)
            ->assertSeeHtml('اختر <bdi dir="ltr">4</bdi> أو أكثر ليكون في كل تصويت خيار حقيقي.');
    });
});
