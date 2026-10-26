<?php

use App\Enums\Difficulty;
use App\Events\GameFinished;
use App\Events\GameStarted;
use App\Events\QuestionStarted;
use App\Livewire\Host\CreateRoom;
use App\Livewire\Host\HostLobby;
use App\Livewire\Host\HostResults;
use App\Livewire\Player\JoinRoom;
use App\Livewire\Player\PlayerResults;
use App\Models\Category;
use App\Models\Room;
use App\Models\User;
use App\Support\Analytics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

const GA_ID = 'G-ABC123DEF4';

/** Analytics as it is on the live site: production, with a valid measurement ID. */
function analyticsOn(): void
{
    app()['env'] = 'production';
    config(['lamma.analytics.id' => GA_ID]);
}

/** What GA is told about a URL: the sanitized path. */
function gaPath(string $url): string
{
    $request = Request::create($url);
    $request->setRouteResolver(fn () => Route::getRoutes()->match($request));

    return Analytics::path($request);
}

/** The events flashed for the next page. */
function flashed(): array
{
    return session('analytics', []);
}

describe('when analytics is on', function () {
    it('is off by default, locally and in tests, with nothing in the page', function () {
        $html = $this->get('/')->assertOk()->getContent();

        expect($html)->not->toContain('lammaAnalytics')->and($html)->not->toContain('lamma-analytics-page')->and($html)->not->toContain('gtag(')
            ->and($html)->not->toContain('consent-banner')->and($html)->not->toContain('Cookie settings');
    });

    it('stays off without an ID, with a malformed one, and outside production', function (string $env, ?string $id) {
        app()['env'] = $env;
        config(['lamma.analytics.id' => $id]);

        expect(Analytics::enabled())->toBeFalse();
        $this->get('/')->assertOk()->assertDontSee('lammaAnalytics', false)->assertDontSee('consent-banner', false);
    })->with([
        'production, no ID' => ['production', null],
        'production, empty ID' => ['production', ''],
        'production, the old placeholder' => ['production', 'G-XXXXXXX'],
        'production, too long' => ['production', 'G-ABC123DEF456'],
        'production, lower case' => ['production', 'g-abc123def4'],
        'production, a UA id' => ['production', 'UA-12345-1'],
        'local, valid ID' => ['local', GA_ID],
        'testing, valid ID' => ['testing', GA_ID],
    ]);

    it('loads on the public and player pages in production', function (string $path) {
        analyticsOn();

        $html = $this->get($path)->assertOk()->getContent();

        expect($html)->toContain('lamma-analytics-page')->and($html)->toContain(GA_ID)->and($html)->toContain('window.lammaAnalytics')
            ->and($html)->toContain("analytics_storage: 'denied'")->and($html)->toContain('allow_google_signals: false')->and($html)->toContain('allow_ad_personalization_signals: false')
            ->and($html)->toContain('send_page_view: false');
    })->with(['/', '/join', '/login', '/register', '/forgot-password']);

    it('loads on the create-room page, and the script tag itself is not in the page (it only loads after consent)', function () {
        analyticsOn();

        $html = $this->actingAs(User::factory()->create())->get('/rooms/create')->assertOk()->getContent();

        expect($html)->toContain(GA_ID)->and($html)->not->toContain('googletagmanager.com');
    });

    it('is never on the admin panel', function () {
        analyticsOn();

        $this->get('/admin/login')->assertOk()->assertDontSee('lammaAnalytics', false)->assertDontSee('lamma-analytics-page', false)->assertDontSee(GA_ID, false);
        expect(Analytics::enabled(Request::create('/admin')))->toBeFalse()->and(Analytics::enabled(Request::create('/admin/questions/3/edit')))->toBeFalse()
            ->and(Analytics::enabled(Request::create('/join')))->toBeTrue();
    });

    it('never hard-codes a measurement ID: it comes from the environment', function () {
        $files = array_merge(
            glob(resource_path('views/*.php')) ?: [],
            collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS)))->map(fn ($file) => $file->getPathname())->all(),
            collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS)))->map(fn ($file) => $file->getPathname())->all(),
            collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)))->map(fn ($file) => $file->getPathname())->all(),
            [base_path('config/app.php'), base_path('config/services.php')],
        );

        foreach ($files as $file) {
            expect(preg_match('/G-[A-Z0-9]{10}/', file_get_contents($file)))->toBe(0, "{$file} has a measurement ID in it");
        }
        expect(Analytics::id())->toBeNull(); // phpunit.xml empties GOOGLE_ANALYTICS_ID, so a developer's own .env cannot turn it on in tests
    });
});

describe('what GA is told about a page', function () {
    it('is the route pattern, never a room code, token, nickname or query string', function (string $url, string $path) {
        expect(gaPath($url))->toBe($path);
    })->with([
        'landing' => ['/', '/'],
        'join with a code' => ['/join?code=K7MP9Z&nickname=Sara', '/join'],
        'host screen' => ['/host/K7MP9Z', '/host/:room'],
        'phone screen' => ['/play/K7MP9Z', '/play/:room'],
        'save your score' => ['/play/K7MP9Z/save/login', '/play/:room/save/:action'],
        'password reset' => ['/reset-password/6f2a9c1e77d84a0b?email=sara@example.com', '/reset-password/:token'],
        'my games' => ['/me/games?page=2', '/me/games'],
        'create room' => ['/rooms/create', '/rooms/create'],
    ]);

    it('puts only the pattern and the route name in the page, whatever the address says', function () {
        analyticsOn();
        $host = User::factory()->create();
        $room = Room::factory()->for($host, 'host')->create(['code' => 'K7MP9Z']);

        $join = $this->get('/join?code=K7MP9Z')->assertOk()->getContent();
        $lobby = $this->actingAs($host)->get('/host/K7MP9Z')->assertOk()->getContent();

        expect($join)->toContain('data-path="/join" data-title="join"')->and($lobby)->toContain('data-path="/host/:room" data-title="host.lobby"');
        // The analytics block of the page (meta + script) carries no code at all.
        foreach ([$join, $lobby] as $html) {
            preg_match('/<meta name="lamma-analytics-page".*?<\/script>/s', $html, $block);
            expect($block[0])->not->toContain('K7MP9Z')->and($block[0])->not->toContain($room->id.'-');
        }
    });
});

describe('the events', function () {
    it('are a fixed list, and each keeps only its own short scalar parameters', function () {
        expect(Analytics::event('player_joined', ['language' => 'ar', 'nickname' => 'Sara', 'code' => 'K7MP9Z', 'user_id' => 5, 'token' => str_repeat('a', 64)]))
            ->toBe(['name' => 'player_joined', 'params' => ['language' => 'ar']])
            ->and(Analytics::event('page_view_with_name', ['name' => 'Sara']))->toBeNull()
            ->and(Analytics::event('room_created', ['difficulty' => 'hard', 'question_count' => 10, 'categories_count' => 2, 'host_email' => 'a@b.c']))
            ->toBe(['name' => 'room_created', 'params' => ['difficulty' => 'hard', 'question_count' => 10, 'categories_count' => 2]])
            ->and(Analytics::event('subcategory_selected', ['parent_category' => 'sports', 'subcategory' => ['x']]))->toBe(['name' => 'subcategory_selected', 'params' => ['parent_category' => 'sports']])
            ->and(Analytics::event('language_switched', ['to' => str_repeat('x', 41)]))->toBe(['name' => 'language_switched', 'params' => []])
            ->and(Analytics::event('play_again_clicked', ['anything' => 1]))->toBe(['name' => 'play_again_clicked', 'params' => []]);
    });

    it('lists exactly the events of the spec', function () {
        expect(array_keys(Analytics::EVENTS))->toBe(['room_created', 'subcategory_selected', 'game_started', 'player_joined', 'game_finished', 'play_again_clicked', 'language_switched', 'sign_up', 'login']);
    });

    it('survive a redirect in the session and reach the next page once', function () {
        analyticsOn();
        $event = [['name' => 'language_switched', 'params' => ['to' => 'ar']]];

        $this->withSession(['analytics' => $event])->get('/')->assertOk()->assertSee('\\u0022name\\u0022:\\u0022language_switched\\u0022', false)->assertSee('\\u0022to\\u0022:\\u0022ar\\u0022', false);

        session()->put('analytics', $event);
        expect(Analytics::pull())->toBe($event)->and(Analytics::pull())->toBe([]);
    });

    it('are not stored at all when analytics is off', function () {
        $this->get('/locale/ar');

        expect(session('analytics'))->toBeNull();
    });

    it('count a registration as a sign_up and not also as a login', function () {
        analyticsOn();

        $this->withSession(['_token' => 't'])->post('/register', ['_token' => 't', 'name' => 'New Host', 'email' => 'new@example.com', 'password' => 'Str0ng!Passw0rd-here', 'password_confirmation' => 'Str0ng!Passw0rd-here']);

        expect(array_column(flashed(), 'name'))->toBe(['sign_up']);
    });

    it('count a log in as a login, carrying nothing about the person', function () {
        analyticsOn();
        $user = User::factory()->create(['email' => 'sara@example.com']);

        $this->withSession(['_token' => 't'])->post('/login', ['_token' => 't', 'email' => $user->email, 'password' => 'password']);

        expect(flashed())->toBe([['name' => 'login', 'params' => []]]);
    });

    it('count a language switch with only the language', function () {
        analyticsOn();

        $this->get('/locale/en');

        expect(flashed())->toBe([['name' => 'language_switched', 'params' => ['to' => 'en']]]);
    });
});

describe('the screens that report them', function () {
    beforeEach(function () {
        Event::fake([GameStarted::class, QuestionStarted::class, GameFinished::class]);
        Queue::fake();
    });

    it('create room: room_created with counts and the difficulty, after the redirect', function () {
        analyticsOn();
        $host = User::factory()->create();
        $a = categoryWithQuestions(8, Difficulty::Hard);
        $b = categoryWithQuestions(8, Difficulty::Hard);

        Livewire::actingAs($host)->test(CreateRoom::class)
            ->call('toggleCategory', $a->id)->call('toggleCategory', $b->id)->set('difficulty', 'hard')->set('questionCount', 5)
            ->call('create');

        expect(flashed())->toBe([['name' => 'room_created', 'params' => ['difficulty' => 'hard', 'question_count' => 5, 'categories_count' => 2]]]);
    });

    it('create room: subcategory_selected with slugs when a chip is switched on, not when it is switched off or the parent is chosen', function () {
        analyticsOn();
        $sports = Category::factory()->create(['slug' => 'sports']);
        $football = Category::factory()->under($sports)->create(['slug' => 'football']);
        Category::factory()->under($sports)->create(['slug' => 'tennis']);

        Livewire::actingAs(User::factory()->create())->test(CreateRoom::class)
            ->call('toggleCategory', $sports->id)->assertNotDispatched('analytics')
            ->call('toggleCategory', $sports->id)
            ->call('toggleSubcategory', $football->id)->assertDispatched('analytics', name: 'subcategory_selected', params: ['parent_category' => 'sports', 'subcategory' => 'football'])
            ->call('toggleSubcategory', $football->id)->assertNotDispatched('analytics');
    });

    it('create room: reports nothing when analytics is off', function () {
        $sports = Category::factory()->create(['slug' => 'sports']);
        $football = Category::factory()->under($sports)->create(['slug' => 'football']);
        Category::factory()->under($sports)->create(['slug' => 'tennis']);

        Livewire::actingAs(User::factory()->create())->test(CreateRoom::class)->call('toggleSubcategory', $football->id)->assertNotDispatched('analytics');
    });

    it('join: player_joined with the language only, no name and no code', function () {
        analyticsOn();
        $room = Room::factory()->create(['code' => 'K7MP9Z']);

        Livewire::test(JoinRoom::class)->set('code', 'K7MP9Z')->set('nickname', 'Sara')->set('language', 'ar')->call('join');

        expect(flashed())->toBe([['name' => 'player_joined', 'params' => ['language' => 'ar']]]);
        expect(json_encode(flashed()))->not->toContain('Sara')->and(json_encode(flashed()))->not->toContain('K7MP9Z');
    });

    it('lobby: game_started with the counts, difficulty and big-screen language', function () {
        analyticsOn();
        $room = gameRoom(players: 3, questions: 5);

        Livewire::actingAs($room->host)->test(HostLobby::class, ['room' => $room])->call('start')
            ->assertDispatched('analytics', name: 'game_started', params: ['player_count' => 3, 'question_count' => 5, 'difficulty' => 'mixed', 'language' => 'both']);
    });

    it('lobby: no game_started when the game cannot start', function () {
        analyticsOn();
        $room = gameRoom(players: 0, questions: 5);

        Livewire::actingAs($room->host)->test(HostLobby::class, ['room' => $room])->call('start')->assertNotDispatched('analytics');
    });

    it('results: game_finished once, with counts and a rounded duration, and play_again_clicked after the redirect', function () {
        analyticsOn();
        $room = finishedGame(['Ali' => 300, 'Sara' => 200], questions: 3);
        $room->update(['started_at' => now()->subSeconds(247), 'finished_at' => now()]);

        $page = Livewire::actingAs($room->host)->test(HostResults::class, ['room' => $room]);

        preg_match("/window\.lammaTrack\('game_finished'.*?\)\"/s", $page->html(), $call);
        expect($call[0])->toContain('\\u0022player_count\\u0022:2')->and($call[0])->toContain('\\u0022question_count\\u0022:3')
            ->and($call[0])->toContain('\\u0022duration_seconds\\u0022:250')->and($call[0])->toContain("'finished-{$room->id}'")
            ->and($call[0])->not->toContain($room->code)->and($call[0])->not->toContain('nickname')->and($call[0])->not->toContain('Ali');

        $page->call('playAgain');
        expect(flashed())->toBe([['name' => 'play_again_clicked', 'params' => []]]);
    });
});

describe('the notice', function () {
    beforeEach(function () {
        Event::fake([GameStarted::class, QuestionStarted::class, GameFinished::class]);
        Queue::fake();
    });

    it('shows on the landing page, the join page and the phone results, with a way back to it', function () {
        analyticsOn();
        $room = finishedGame(['Ali' => 300, 'Sara' => 200], questions: 3);
        $room->players()->update(['locale' => 'en']);

        $landing = $this->get('/')->getContent();
        $join = $this->get('/join')->getContent();
        $results = Livewire::withCookie('lamma_guest', $room->players()->first()->guest_token)->test(PlayerResults::class, ['room' => $room])->html();

        foreach ([$landing, $join, $results] as $html) {
            expect($html)->toContain('data-test="consent-banner"')->and($html)->toContain('data-test="consent-accept"')->and($html)->toContain('data-test="consent-decline"')
                ->and($html)->toContain('x-data="lammaConsent"')->and($html)->toContain('Help us improve Lamma');
        }
        expect($landing)->toContain('data-test="cookie-settings"')->and($join)->toContain('data-test="cookie-settings"');
    });

    it('is bilingual and right-to-left in Arabic', function () {
        analyticsOn();

        $this->withSession(['locale' => 'ar'])->get('/')->assertSee('ساعدنا في تحسين لمّة')->assertSee('أوافق')->assertSee('أرفض')->assertSee('إعدادات ملفات تعريف الارتباط')->assertSee('dir="rtl"', false);
    });

    it('never shows on the host screens: create room, lobby, question, reveal or the podium', function () {
        analyticsOn();
        $room = gameRoom(players: 2, questions: 3);

        $create = $this->actingAs($room->host)->get('/rooms/create')->getContent();
        $lobby = $this->actingAs($room->host)->get(route('host.lobby', $room->code))->getContent();
        startGame($room);
        $question = $this->actingAs($room->host)->get(route('host.lobby', $room->code))->getContent();
        $room->roomQuestions()->update(['revealed_at' => now()]);
        $reveal = $this->actingAs($room->host)->get(route('host.lobby', $room->code))->getContent();
        $podium = Livewire::actingAs($room->host)->test(HostResults::class, ['room' => finishedGame(['Ali' => 100])])->html();

        foreach ([$create, $lobby, $question, $reveal, $podium] as $html) {
            expect($html)->not->toContain('consent-banner')->and($html)->not->toContain('lammaConsent');
        }
    });

    it('is not in a phone question or reveal, and not at all when analytics is off', function () {
        $this->get('/')->assertDontSee('consent-banner', false);
        $this->get('/join')->assertDontSee('consent-banner', false)->assertDontSee('Cookie settings', false);
    });
});
