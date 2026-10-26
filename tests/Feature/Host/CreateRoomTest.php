<?php

use App\Enums\Difficulty;
use App\Enums\HostScreenLocale;
use App\Enums\RoomStatus;
use App\Livewire\Host\CreateRoom;
use App\Models\Category;
use App\Models\Question;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\RoomQuestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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

        $page = createRoomPage()
            ->call('toggleCategory', $geo->id)->call('toggleCategory', $sci->id)
            ->set('questionCount', 15)->set('secondsPerQuestion', 30)->set('difficulty', 'hard')->set('hostScreenLocale', 'en');

        // (html(), not assertSee: the update response carries the markup JSON-escaped, so the middle dot would not match)
        expect($page->html())->toContain('Geography · Science');
        $page->assertSeeInOrder(['Categories', 'Questions', '15', 'Time', '30 s each', 'Difficulty', 'Hard', 'Big screen', 'English']);
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

describe('fresh questions for the host\'s usual group', function () {
    /** The host's latest game: 8 of the category's 14 questions, played by one guest who saw them all. */
    function lastGroupSawEight(User $host): void
    {
        $ids = Question::pluck('id')->take(8)->all();
        $room = Room::factory()->for($host, 'host')->finished()->create();
        RoomPlayer::factory()->for($room)->create(['guest_token' => str_repeat('q', 64)]);
        foreach ($ids as $position => $id) {
            RoomQuestion::factory()->create(['room_id' => $room->id, 'question_id' => $id, 'position' => $position + 1]);
            DB::table('question_seen')->insert(['question_id' => $id, 'user_id' => null, 'guest_token' => str_repeat('q', 64), 'room_id' => $room->id, 'seen_at' => now()]);
        }
    }

    it('warns how many are left when that is fewer than the game needs, and still lets the host create', function () {
        $category = categoryWithQuestions(14);
        lastGroupSawEight($this->host);

        $page = createRoomPage()->call('toggleCategory', $category->id);

        $page->assertSeeHtml('data-test="few-fresh-questions"')->assertSeeHtml('<bdi dir="ltr">6</bdi>')
            ->call('create')->assertHasNoErrors();
        expect(Room::where('host_id', $this->host->id)->active()->count())->toBe(1);
    });

    it('says nothing when enough are left, when nothing is chosen yet, or when there was no earlier game', function () {
        $category = categoryWithQuestions(14);

        createRoomPage()->call('toggleCategory', $category->id)->assertDontSeeHtml('data-test="few-fresh-questions"');

        lastGroupSawEight($this->host);
        createRoomPage()->assertDontSeeHtml('data-test="few-fresh-questions"')
            ->call('toggleCategory', $category->id)->set('questionCount', 5)->assertDontSeeHtml('data-test="few-fresh-questions"')
            ->set('questionCount', 10)->assertSeeHtml('data-test="few-fresh-questions"');
    });

    it('shows the warning in Arabic too', function () {
        $category = categoryWithQuestions(14);
        lastGroupSawEight($this->host);

        $this->actingAs($this->host)->withSession(['locale' => 'ar']);
        app()->setLocale('ar');

        createRoomPage()->call('toggleCategory', $category->id)->assertSee('من الأسئلة الجديدة لهذه المجموعة');
    });
});

describe('subcategories', function () {
    /** Sports (3 own questions) with Football (5), Basketball (4), Tennis (6), plus Science (12). */
    function sportsWithSubcategories(): array
    {
        $sports = categoryWithQuestions(3, category: ['name' => ['en' => 'Sports', 'ar' => 'رياضة'], 'slug' => 'sports', 'sort_order' => 1]);
        $football = categoryWithQuestions(5, category: ['name' => ['en' => 'Football', 'ar' => 'كرة القدم'], 'slug' => 'football', 'parent_id' => $sports->id, 'sort_order' => 1]);
        $basketball = categoryWithQuestions(4, category: ['name' => ['en' => 'Basketball', 'ar' => 'كرة السلة'], 'slug' => 'basketball', 'parent_id' => $sports->id, 'sort_order' => 2]);
        $tennis = categoryWithQuestions(6, category: ['name' => ['en' => 'Tennis', 'ar' => 'التنس'], 'slug' => 'tennis', 'parent_id' => $sports->id, 'sort_order' => 3]);
        $science = categoryWithQuestions(12, category: ['name' => ['en' => 'Science', 'ar' => 'علوم'], 'slug' => 'science', 'sort_order' => 2]);

        return [$sports, $football, $basketball, $tennis, $science];
    }

    it('offers only the top-level categories as tiles, with an expand control just on those that have subcategories', function () {
        [$sports] = sportsWithSubcategories();

        $html = createRoomPage()->html();

        expect($html)->toContain('data-test="category-sports"')->and($html)->toContain('data-test="category-science"')
            ->and($html)->not->toContain('data-test="category-football"')
            ->and(substr_count($html, 'aria-expanded='))->toBe(1)
            ->and($html)->toContain('aria-controls="subcategories-'.$sports->id.'"')
            ->and($html)->not->toContain('data-test="subcategories"');
    });

    it('shows the subcategory chips when expanded, one category at a time, and hides them again', function () {
        [$sports, , , , $science] = sportsWithSubcategories();

        createRoomPage()
            ->call('toggleExpanded', $sports->id)
            ->assertSet('expandedId', $sports->id)
            ->assertSeeHtml('aria-expanded="true"')->assertSeeHtml('data-test="subcategories"')
            ->assertSeeHtml('data-test="subcategory-football"')->assertSeeHtml('data-test="subcategory-tennis"')
            ->call('toggleExpanded', $science->id)->assertSet('expandedId', null)->assertDontSeeHtml('data-test="subcategories"')
            ->call('toggleExpanded', $sports->id)->call('toggleExpanded', $sports->id)->assertSet('expandedId', null);
    });

    it('uses real buttons that are at least 44px tall, with aria-pressed', function () {
        [$sports] = sportsWithSubcategories();

        $html = createRoomPage()->call('toggleExpanded', $sports->id)->html();

        preg_match_all('/<button[^>]*data-test="subcategory-[a-z]+"[^>]*>/s', $html, $chips);
        expect($chips[0])->toHaveCount(3);
        foreach ($chips[0] as $chip) {
            expect($chip)->toContain('aria-pressed="false"')->and($chip)->toContain('min-h-11');
        }
    });

    it('selects all of a category with its tile, and switches it off with a second tap', function () {
        [$sports] = sportsWithSubcategories();

        createRoomPage()
            ->call('toggleCategory', $sports->id)->assertSet('categoryIds', [$sports->id])
            ->call('toggleExpanded', $sports->id)
            ->assertSeeHtml('data-test="subcategory-football"')
            ->call('toggleCategory', $sports->id)->assertSet('categoryIds', []);
    });

    it('selects only the chosen subcategories and shows the parent as partly chosen', function () {
        [$sports, $football, $basketball] = sportsWithSubcategories();

        $page = createRoomPage()->call('toggleExpanded', $sports->id)
            ->call('toggleSubcategory', $football->id)->assertSet('categoryIds', [$football->id]);

        preg_match('/<button[^>]*data-test="category-sports"[^>]*>/s', $page->html(), $tile);
        expect($tile[0])->toContain('aria-pressed="mixed"');
        expect($page->html())->toContain('<bdi dir="ltr">1</bdi> of <bdi dir="ltr">3</bdi>');
        $page->call('toggleSubcategory', $basketball->id)->assertSet('categoryIds', [$football->id, $basketball->id]);
        expect($page->html())->toContain('<bdi dir="ltr">2</bdi> of <bdi dir="ltr">3</bdi>');
        $page->call('toggleSubcategory', $football->id)->assertSet('categoryIds', [$basketball->id]);
    });

    it('treats every subcategory chosen one by one as the whole category', function () {
        [$sports, $football, $basketball, $tennis] = sportsWithSubcategories();

        createRoomPage()
            ->call('toggleSubcategory', $football->id)->call('toggleSubcategory', $basketball->id)->call('toggleSubcategory', $tennis->id)
            ->assertSet('categoryIds', [$sports->id]);
    });

    it('selects everything when the parent is tapped from a partial choice, then clears it', function () {
        [$sports, $football] = sportsWithSubcategories();

        createRoomPage()
            ->call('toggleSubcategory', $football->id)
            ->call('toggleCategory', $sports->id)->assertSet('categoryIds', [$sports->id])
            ->call('toggleCategory', $sports->id)->assertSet('categoryIds', []);
    });

    it('leaves the siblings when a chip is switched off under a fully chosen parent', function () {
        [$sports, $football, $basketball, $tennis] = sportsWithSubcategories();

        createRoomPage()
            ->call('toggleCategory', $sports->id)
            ->call('toggleSubcategory', $football->id)
            ->assertSet('categoryIds', [$basketball->id, $tennis->id]);
    });

    it('ignores ids that are not an active subcategory or category', function () {
        [, $football] = sportsWithSubcategories();
        $football->update(['is_active' => false]);

        createRoomPage()->call('toggleSubcategory', $football->id)->call('toggleSubcategory', 999999)->call('toggleCategory', $football->id)->call('toggleExpanded', 999999)
            ->assertSet('categoryIds', [])->assertSet('expandedId', null);
    });

    it('lists the selection compactly in the summary', function () {
        [, $football, $basketball, , $science] = sportsWithSubcategories();

        $page = createRoomPage()
            ->call('toggleSubcategory', $football->id)->call('toggleSubcategory', $basketball->id)->call('toggleCategory', $science->id);
        expect($page->html())->toContain('Sports: Football, Basketball · Science');
    });

    it('counts the available questions for the selection and the difficulty', function () {
        [$sports, $football, $basketball, , $science] = sportsWithSubcategories();

        createRoomPage()
            ->call('toggleSubcategory', $football->id)->assertSet('available', 5)
            ->call('toggleSubcategory', $basketball->id)->assertSet('available', 9)
            ->call('toggleCategory', $sports->id)->assertSet('available', 3 + 5 + 4 + 6)
            ->call('toggleCategory', $science->id)->assertSet('available', 3 + 5 + 4 + 6 + 12)
            ->set('difficulty', 'hard')->assertSet('available', Question::query()->where('difficulty', Difficulty::Hard)->count());
    });

    it('says how many are available, and warns when the subcategory alone is too small', function () {
        [, $football] = sportsWithSubcategories();

        createRoomPage()
            ->call('toggleSubcategory', $football->id)
            ->assertSeeHtml('<bdi dir="ltr">5</bdi> available')
            ->assertSee('Only 5 questions match this choice, but you picked 10.')
            ->assertSeeHtml('data-test="not-enough-questions"');
    });

    it('creates a room that stores the subcategory ids, not the parent', function () {
        [, $football, $basketball, , $science] = sportsWithSubcategories();

        createRoomPage()
            ->call('toggleSubcategory', $football->id)->call('toggleSubcategory', $basketball->id)->call('toggleCategory', $science->id)
            ->set('questionCount', 5)->call('create')->assertHasNoErrors();

        expect(Room::sole()->settings->categoryIds)->toEqualCanonicalizing([$football->id, $basketball->id, $science->id]);
    });

    it('renders with the subcategories expanded in English and in Arabic', function () {
        [$sports, $football] = sportsWithSubcategories();

        $this->actingAs($this->host)->withSession(['locale' => 'en']);
        createRoomPage()->call('toggleExpanded', $sports->id)->call('toggleSubcategory', $football->id)
            ->assertSee('Subcategories of Sports')->assertSee('Football')->assertSee('Basketball')->assertSee('Pick all of it, or only some');

        app()->setLocale('ar');
        createRoomPage()->call('toggleExpanded', $sports->id)->call('toggleSubcategory', $football->id)
            ->assertSee('كرة القدم')->assertSee('كرة السلة')->assertSee('الفئات الفرعية في رياضة')->assertSee('اختر الفئة كاملة أو بعضها فقط')->assertSee('رياضة: كرة القدم');
    });
});
