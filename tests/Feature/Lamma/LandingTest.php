<?php

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows the active categories in the visitor language and skips inactive ones', function () {
    Category::factory()->create(['slug' => 'science', 'name' => ['en' => 'Science Lab', 'ar' => 'مختبر العلوم'], 'sort_order' => 1]);
    Category::factory()->inactive()->create(['name' => ['en' => 'Hidden Topic', 'ar' => 'فئة مخفية']]);

    $this->withSession(['locale' => 'en'])->get('/')
        ->assertOk()
        ->assertSee('Science Lab')
        ->assertDontSee('Hidden Topic');

    $this->withSession(['locale' => 'ar'])->get('/')
        ->assertSee('مختبر العلوم')
        ->assertDontSee('فئة مخفية');
});

it('leaves out the categories section when there are none', function () {
    $this->withSession(['locale' => 'en'])->get('/')
        ->assertOk()
        ->assertDontSee('id="categories"', false)
        ->assertDontSee('href="#categories"', false);
});

it('has no untranslated English copy on the Arabic page', function () {
    $page = $this->withSession(['locale' => 'ar'])->get('/')->assertOk()->assertSee('استضف لعبة');

    foreach (['Friends join', 'Play and climb', 'Live scoreboard', 'Fair for everyone', 'Ready for game night?', 'Made for the living room'] as $english) {
        $page->assertDontSee($english);
    }
});

it('sends guests to log in and hosts to room creation from the Host a game button', function () {
    $this->withSession(['locale' => 'en'])->get('/')->assertSee('href="'.route('login').'"', false);

    $this->actingAs(User::factory()->create())->get('/')->assertSee('href="'.url('/rooms/create').'"', false);
});

it('has a join box that submits the code to /join', function () {
    $this->withSession(['locale' => 'en'])->get('/')
        ->assertSee('action="'.url('/join').'"', false)
        ->assertSee('name="code"', false)
        ->assertSee('maxlength="6"', false);
});

it('sets lang and dir on the page from the visitor language', function (string $locale, string $dir) {
    $this->withSession(['locale' => $locale])->get('/')
        ->assertOk()
        ->assertSee("<html lang=\"{$locale}\" dir=\"{$dir}\"", false);
})->with([['en', 'ltr'], ['ar', 'rtl']]);

it('lists up to six categories in sort order with their icon', function () {
    foreach (range(1, 8) as $n) {
        Category::factory()->create(['slug' => $n === 1 ? 'geography' : "topic-{$n}", 'name' => ['en' => "Topic {$n}", 'ar' => "موضوع {$n}"], 'sort_order' => $n]);
    }

    $html = $this->withSession(['locale' => 'en'])->get('/')->assertOk()->assertSee('id="categories"', false)->getContent();

    expect($html)->toContain('Topic 1')->toContain('Topic 6')->not->toContain('Topic 7')
        ->and(strpos($html, 'Topic 1'))->toBeLessThan(strpos($html, 'Topic 6'))
        ->and($html)->toContain('M3 12h18M12 3c3 3.5'); // geography -> globe icon, never the seeded emoji
});

it('has the main sections and their anchors', function () {
    Category::factory()->create();

    $this->withSession(['locale' => 'en'])->get('/')
        ->assertSee('id="how"', false)->assertSee('id="hosts"', false)->assertSee('id="categories"', false)
        ->assertSee('href="#how"', false);
});

it('shows Log in and Sign up to guests, and the account menu to signed-in users', function () {
    $this->withSession(['locale' => 'en'])->get('/')
        ->assertSee('href="'.route('login').'"', false)->assertSee('href="'.route('register').'"', false)
        ->assertDontSee('data-test="user-menu-button"', false);

    $page = $this->actingAs(User::factory()->create(['name' => 'Sara Ahmad']))->withSession(['locale' => 'en'])->get('/');

    $page->assertSee('data-test="user-menu-button"', false)->assertSee('Sara Ahmad')
        ->assertSee('href="'.route('me.games').'"', false)->assertSee('href="'.route('profile.edit').'"', false)
        ->assertDontSee('href="'.route('register').'"', false)->assertDontSee('/dashboard', false);
});

it('points the join box and the host button at the routes the next phase fills in', function () {
    $page = $this->withSession(['locale' => 'en'])->get('/');

    $page->assertSee('action="'.route('join').'"', false);
    expect(route('join', absolute: false))->toBe('/join')->and(route('rooms.create', absolute: false))->toBe('/rooms/create');
});

it('lets hosts reach room creation but sends guests to log in', function () {
    $this->get('/rooms/create')->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())->get('/rooms/create')->assertOk()->assertSee('Set up your game');
});

it('sends the join box to the join screen with the code prefilled', function () {
    $this->get('/join?code=k7mp')->assertOk()->assertSee('value="K7MP"', false)->assertSee('Join a game');
});
