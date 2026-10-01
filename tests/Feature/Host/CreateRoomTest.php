<?php

use App\Enums\Difficulty;
use App\Enums\HostScreenLocale;
use App\Enums\RoomStatus;
use App\Livewire\Host\CreateRoom;
use App\Models\Category;
use App\Models\Question;
use App\Models\Room;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->host = User::factory()->create();
});

function createRoomPage(): Testable
{
    return Livewire::actingAs(test()->host)->test(CreateRoom::class);
}

describe('the page', function () {
    it('is for logged-in hosts only', function () {
        $this->get(route('rooms.create'))->assertRedirect(route('login'));

        $this->actingAs($this->host)->get(route('rooms.create'))->assertOk()->assertSeeLivewire(CreateRoom::class);
    });

    it('lists the active categories and hides inactive ones', function () {
        Category::factory()->create(['name' => ['en' => 'Geography', 'ar' => 'جغرافيا'], 'slug' => 'geography']);
        Category::factory()->inactive()->create(['name' => ['en' => 'Secret Topic', 'ar' => 'سري']]);

        $this->actingAs($this->host)->withSession(['locale' => 'en'])->get(route('rooms.create'))
            ->assertSee('Geography')->assertDontSee('Secret Topic');
    });

    it('is translated and right-to-left in Arabic', function () {
        $this->actingAs($this->host)->withSession(['locale' => 'ar'])->get(route('rooms.create'))
            ->assertSee('dir="rtl"', false)->assertSee('جهّز لعبتك')->assertSee('أنشئ الغرفة')
            ->assertDontSee('Set up your game');
    });

    it('starts with the specified defaults', function () {
        createRoomPage()
            ->assertSet('categoryIds', [])
            ->assertSet('questionCount', 10)
            ->assertSet('secondsPerQuestion', 20)
            ->assertSet('difficulty', 'mixed')
            ->assertSet('hostScreenLocale', 'both');
    });

    it('offers 5/10/15/20 questions and 10/20/30 seconds, and four difficulties and three languages', function () {
        $html = $this->actingAs($this->host)->withSession(['locale' => 'en'])->get(route('rooms.create'))->getContent();

        foreach (['>5<', '>10<', '>15<', '>20<', '>10 s<', '>20 s<', '>30 s<', '>Easy<', '>Medium<', '>Hard<', '>Mixed<', '>English<', '>العربية<', '>Both<'] as $option) {
            expect($html)->toContain($option);
        }
    });
});

describe('choosing categories', function () {
    it('toggles a category on and off', function () {
        $category = Category::factory()->create();

        createRoomPage()
            ->call('toggleCategory', $category->id)->assertSet('categoryIds', [$category->id])
            ->call('toggleCategory', $category->id)->assertSet('categoryIds', []);
    });

    it('ignores unknown and inactive categories', function () {
        $inactive = Category::factory()->inactive()->create();

        createRoomPage()->call('toggleCategory', $inactive->id)->call('toggleCategory', 99999)->assertSet('categoryIds', []);
    });

    it('shows an inline error and a disabled button until a category is chosen', function () {
        $category = categoryWithQuestions(12);

        createRoomPage()
            ->assertSee('Pick at least one category.')
            ->assertSeeHtml('data-test="create-room-button"')
            ->assertSeeHtml('border-dashed') // the disabled button look
            ->call('toggleCategory', $category->id)
            ->assertDontSee('Pick at least one category.')
            ->assertSee("You'll get a room code to share.");
    });

    it('will not create without a category, even if the button is forced', function () {
        categoryWithQuestions(12);

        createRoomPage()->call('create')->assertHasErrors(['categoryIds' => 'required'])->assertNoRedirect();

        expect(Room::count())->toBe(0);
    });
});

describe('the summary card', function () {
    it('updates live with the choices', function () {
        $geo = categoryWithQuestions(12, category: ['name' => ['en' => 'Geography', 'ar' => 'جغرافيا']]);
        $sci = categoryWithQuestions(12, category: ['name' => ['en' => 'Science', 'ar' => 'علوم']]);

        createRoomPage()
            ->call('toggleCategory', $geo->id)->call('toggleCategory', $sci->id)
            ->set('questionCount', 15)->set('secondsPerQuestion', 30)->set('difficulty', 'hard')->set('hostScreenLocale', 'en')
            ->assertSeeInOrder(['Categories', 'Geography, Science', 'Questions', '15', 'Time', '30 s each', 'Difficulty', 'Hard', 'Big screen', 'English']);
    });

    it('shows Mixed and both languages by default', function () {
        createRoomPage()->assertSeeInOrder(['Difficulty', 'Mixed', 'Big screen', 'English +']);
    });
});

describe('question availability', function () {
    it('blocks creation and says how many questions are available when there are too few', function () {
        $category = categoryWithQuestions(6);

        createRoomPage()
            ->call('toggleCategory', $category->id)
            ->assertSee('Only 6 questions match this choice, but you picked 10.')
            ->assertSeeHtml('data-test="not-enough-questions"')
            ->assertSeeHtml('border-dashed')
            ->call('create')
            ->assertHasErrors('questionCount')
            ->assertNoRedirect();

        expect(Room::count())->toBe(0);
    });

    it('lets the host fix it with fewer questions or more categories', function () {
        $a = categoryWithQuestions(6);
        $b = categoryWithQuestions(6);

        createRoomPage()
            ->call('toggleCategory', $a->id)
            ->assertSee('Only 6 questions')
            ->set('questionCount', 5)->assertDontSee('Only 6 questions')
            ->set('questionCount', 10)->assertSee('Only 6 questions')
            ->call('toggleCategory', $b->id)->assertDontSee('Only 12 questions')->assertDontSee('Only 6 questions');
    });

    it('counts only the chosen difficulty', function () {
        $category = categoryWithQuestions(12, Difficulty::Easy);

        createRoomPage()
            ->call('toggleCategory', $category->id)
            ->set('difficulty', 'hard')->assertSee('Only 0 questions')
            ->set('difficulty', 'easy')->assertDontSee('Only');
    });

    it('does not count questions missing a translation', function () {
        $category = categoryWithQuestions(6);
        Question::factory()->count(6)->withOptions()->create(['category_id' => $category->id, 'text' => ['en' => 'English only?']]);

        createRoomPage()->call('toggleCategory', $category->id)->assertSee('Only 6 questions');
    });
});

describe('creating the room', function () {
    it('creates a lobby with the chosen settings and goes to the host screen', function () {
        $category = categoryWithQuestions(12, Difficulty::Medium);

        $page = createRoomPage()
            ->call('toggleCategory', $category->id)
            ->set('questionCount', 15)->set('secondsPerQuestion', 10)->set('difficulty', 'medium')->set('hostScreenLocale', 'ar');
        $page->set('questionCount', 10)->call('create')->assertHasNoErrors();

        $room = Room::sole();
        $page->assertRedirect(route('host.lobby', $room->code));

        expect($room->host_id)->toBe($this->host->id)
            ->and($room->status)->toBe(RoomStatus::Lobby)
            ->and($room->code)->toMatch('/^[A-HJ-KM-NP-Z2-9]{6}$/')
            ->and($room->settings->categoryIds)->toBe([$category->id])
            ->and($room->settings->questionCount)->toBe(10)
            ->and($room->settings->secondsPerQuestion)->toBe(10)
            ->and($room->settings->difficulty)->toBe(Difficulty::Medium)
            ->and($room->settings->hostScreenLocale)->toBe(HostScreenLocale::Ar);
    });

    it('stores Mixed as no difficulty filter, and the defaults as 10 questions / 20 s / both languages', function () {
        $category = categoryWithQuestions(12);

        createRoomPage()->call('toggleCategory', $category->id)->call('create');

        $settings = Room::sole()->settings;
        expect($settings->difficulty)->toBeNull()
            ->and($settings->questionCount)->toBe(10)
            ->and($settings->secondsPerQuestion)->toBe(20)
            ->and($settings->hostScreenLocale)->toBe(HostScreenLocale::Both);
    });

    it('closes the host\'s previous room', function () {
        $category = categoryWithQuestions(12);
        $old = Room::factory()->for($this->host, 'host')->create();

        createRoomPage()->call('toggleCategory', $category->id)->call('create');

        expect($old->fresh()->status)->toBe(RoomStatus::Finished)
            ->and(Room::where('host_id', $this->host->id)->active()->count())->toBe(1);
    });

    it('rejects values outside the allowed lists', function (string $property, mixed $value) {
        $category = categoryWithQuestions(12);

        createRoomPage()->call('toggleCategory', $category->id)->set($property, $value)->call('create')
            ->assertHasErrors($property)->assertNoRedirect();

        expect(Room::count())->toBe(0);
    })->with([
        'question count' => ['questionCount', 7],
        'too many questions' => ['questionCount', 100],
        'seconds' => ['secondsPerQuestion', 45],
        'difficulty' => ['difficulty', 'impossible'],
        'screen language' => ['hostScreenLocale', 'fr'],
    ]);
});
