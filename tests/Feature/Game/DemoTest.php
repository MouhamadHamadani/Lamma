<?php

use App\Enums\GameMode;
use App\Enums\HostScreenLocale;
use App\Enums\RoomStatus;
use App\Events\GameStarted;
use App\Events\PlayerJoined;
use App\Events\QuestionStarted;
use App\Events\RoomClosed;
use App\Game\Bots;
use App\Game\DemoHost;
use App\Game\DemoRoom;
use App\Game\GameEngine;
use App\Game\GameStartException;
use App\Game\PlayerIdentity;
use App\Game\RoomManager;
use App\Game\RoomPresence;
use App\Game\RoomRoster;
use App\Livewire\Host\HostLobby;
use App\Livewire\Host\HostResults;
use App\Models\Category;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
 * The no-signup demo (Phase 14): a room with no host account, two bots, fixed settings, an IP limit, and a host cookie that is the only way into its big screen.
 */

beforeEach(function () {
    Queue::fake();
    Event::fake([GameStarted::class, QuestionStarted::class, RoomClosed::class, PlayerJoined::class]);
    at('12:00:00.000');
});

describe('the demo page', function () {
    it('explains the demo and has the start button, in both languages', function (string $locale, string $title, string $button) {
        $this->withSession(['locale' => $locale])->get('/demo')
            ->assertOk()
            ->assertSee($title)->assertSee($button)
            ->assertSee('<form method="post" action="'.route('demo.start').'"', false)
            ->assertSee('data-test="start-demo"', false)
            ->assertSee("<html lang=\"{$locale}\"", false);
    })->with([
        'english' => ['en', 'Feel the game in two minutes.', 'Start a demo game'],
        'arabic' => ['ar', 'جرّب اللعبة في دقيقتين.', 'ابدأ لعبة تجريبية'],
    ]);

    it('says on a small screen to open it on a bigger one, and still has the button', function () {
        $page = $this->withSession(['locale' => 'en'])->get('/demo')->assertOk();

        $page->assertSee('data-test="small-screen-note"', false)->assertSee('works best on a laptop or TV')
            ->assertSee('md:hidden', false)->assertSee('data-test="start-demo"', false);
    });

    it('needs no account', function () {
        $this->get('/demo')->assertOk();
        demoContent();
        $this->post(route('demo.start'))->assertRedirect();
    });
});

describe('starting a demo', function () {
    it('makes a demo room without a host account, with the fixed settings and the two bots', function () {
        demoContent();

        $response = $this->post(route('demo.start'));
        $room = Room::sole();

        $response->assertRedirect(route('host.lobby', $room->code));
        expect($room->is_demo)->toBeTrue()->and($room->host_id)->toBeNull()->and($room->status)->toBe(RoomStatus::Lobby)->and($room->game_mode)->toBe(GameMode::Classic)
            ->and($room->host_token_hash)->toHaveLength(64);

        $settings = $room->settings;
        expect($settings->questionCount)->toBe(4)->and($settings->secondsPerQuestion)->toBe(15)->and($settings->difficulty)->toBeNull()
            ->and($settings->hostScreenLocale)->toBe(HostScreenLocale::Both)->and($settings->doubleFinal)->toBeTrue()->and($settings->showPhotos)->toBeFalse()
            ->and($settings->categoryIds)->toHaveCount(2);
        expect(Category::whereIn('id', $settings->categoryIds)->pluck('slug')->sort()->values()->all())->toBe(['arab-world', 'general-knowledge']);
    });

    it('seats Sara (English) and Karim (Arabic) as connected, ready bots with no account, token or photo', function () {
        demoContent();
        $this->post(route('demo.start'));

        $bots = Room::sole()->players()->orderBy('id')->get();

        expect($bots->pluck('nickname')->all())->toBe(['Sara', 'Karim'])->and($bots->pluck('locale')->all())->toBe(['en', 'ar'])
            ->and($bots->every(fn (RoomPlayer $bot) => $bot->is_bot && $bot->is_ready && $bot->left_at === null && $bot->user_id === null && $bot->guest_token === null && $bot->avatar_path === null))->toBeTrue();
    });

    it('puts the host cookie on the response: encrypted, httpOnly, and the room keeps only its hash', function () {
        demoContent();
        $response = $this->post(route('demo.start'));

        $response->assertCookie('lamma_demo_host');
        $token = $response->getCookie('lamma_demo_host')->getValue();

        expect($token)->toMatch('/^[A-Za-z0-9]{64}$/')->and(Room::sole()->host_token_hash)->toBe(hash('sha256', $token))->and(Room::sole()->host_token_hash)->not->toBe($token)
            ->and($response->getCookie('lamma_demo_host', decrypt: false)->isHttpOnly())->toBeTrue()
            ->and($response->getCookie('lamma_demo_host', decrypt: false)->getValue())->not->toBe($token);
    });

    it('keeps one token for one browser, so two demos are both its own', function () {
        demoContent();
        app()->instance('request', Request::create('/demo', 'POST', cookies: ['lamma_demo_host' => DEMO_TOKEN]));

        $one = app(DemoRoom::class)->start('10.0.0.1');
        $two = app(DemoRoom::class)->start('10.0.0.1');

        expect($one->host_token_hash)->toBe(hash('sha256', DEMO_TOKEN))->and($two->host_token_hash)->toBe($one->host_token_hash);
    });

    it('refuses with a message when the demo categories cannot fill a game', function () {
        categoryWithQuestions(1, category: ['slug' => 'arab-world']);

        $this->from('/demo')->post(route('demo.start'))->assertRedirect('/demo')->assertSessionHasErrors('demo');
        expect(Room::count())->toBe(0);
    });

    it('leaves a real host with their own room when a demo starts: a demo closes nobody', function () {
        demoContent();
        $real = Room::factory()->create();

        $this->post(route('demo.start'));

        expect($real->fresh()->status)->toBe(RoomStatus::Lobby);
    });

    it('does not make a second demo close the first: a browser can have several', function () {
        demoContent();
        $this->post(route('demo.start'));
        $this->post(route('demo.start'));

        expect(Room::where('status', RoomStatus::Lobby)->count())->toBe(2);
    });
});

describe('the limit', function () {
    it('allows 5 demo rooms per IP per hour and refuses the 6th with a message, creating nothing', function () {
        demoContent();

        foreach (range(1, 5) as $n) {
            $this->post(route('demo.start'))->assertRedirect()->assertSessionHasNoErrors();
        }
        expect(Room::count())->toBe(5);

        $this->from('/demo')->post(route('demo.start'))->assertRedirect('/demo')->assertSessionHasErrors('demo');
        expect(Room::count())->toBe(5)->and(session('errors')->first('demo'))->toContain('minutes');
    });

    it('counts per address: another IP is not held back', function () {
        demoContent();
        foreach (range(1, 5) as $n) {
            $this->post(route('demo.start'));
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.9.8.7'])->post(route('demo.start'))->assertRedirect()->assertSessionHasNoErrors();

        expect(Room::count())->toBe(6);
    });

    it('lets the same address start again an hour later', function () {
        demoContent();
        foreach (range(1, 5) as $n) {
            $this->post(route('demo.start'));
        }
        $this->post(route('demo.start'))->assertSessionHasErrors('demo');

        $this->travel(61)->minutes();
        $this->post(route('demo.start'))->assertRedirect()->assertSessionHasNoErrors();

        expect(Room::count())->toBe(6);
    });

    it('is a setting', function () {
        demoContent();
        config(['lamma.demo.rooms_per_ip_per_hour' => 2]);

        $this->post(route('demo.start'));
        $this->post(route('demo.start'));
        $this->post(route('demo.start'))->assertSessionHasErrors('demo');

        expect(Room::count())->toBe(2);
    });
});

describe('who can open a demo room\'s big screen', function () {
    it('lets the browser with the host cookie in', function () {
        $room = demoGame();

        $this->withCookie('lamma_demo_host', DEMO_TOKEN)->get(route('host.lobby', $room->code))->assertOk()->assertSeeLivewire(HostLobby::class);
    });

    it('forbids a browser without the cookie, a wrong token, or another demo\'s cookie', function (?string $token) {
        $room = demoGame();
        $other = demoGame(token: OTHER_DEMO_TOKEN);

        $request = $token === null ? $this : $this->withCookie('lamma_demo_host', $token);
        $request->get(route('host.lobby', $room->code))->assertForbidden();
        // The same cookie opens its own room and only that one.
        $this->withCookie('lamma_demo_host', OTHER_DEMO_TOKEN)->get(route('host.lobby', $other->code))->assertOk();
    })->with([
        'no cookie' => [null],
        'a wrong token' => ['f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3'],
        'the other demo\'s token' => [OTHER_DEMO_TOKEN],
        'a malformed token' => ['short'],
    ]);

    it('forbids a logged-in stranger, a player of that room and a real host without the cookie', function () {
        $room = demoGame();
        $player = User::factory()->create();
        RoomPlayer::factory()->for($room)->forUser($player)->create();
        $someHost = User::factory()->create();

        $this->actingAs($player)->get(route('host.lobby', $room->code))->assertForbidden();
        $this->actingAs($someHost)->get(route('host.lobby', $room->code))->assertForbidden();
        $this->actingAs(Room::factory()->create()->host)->get(route('host.lobby', $room->code))->assertForbidden();
    });

    it('does not open a REAL room with a demo cookie: a real room still needs its account', function () {
        $real = Room::factory()->create();

        $this->withCookie('lamma_demo_host', DEMO_TOKEN)->get(route('host.lobby', $real->code))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->withCookie('lamma_demo_host', DEMO_TOKEN)->get(route('host.lobby', $real->code))->assertForbidden();
        $this->actingAs($real->host)->get(route('host.lobby', $real->code))->assertOk();
    });

    it('never treats a real room as a demo: no hash, no host', function () {
        $real = Room::factory()->create();

        expect(app(PlayerIdentity::class)->hostFor($real))->toBeNull()
            ->and($real->isHostedBy(new DemoHost($real->id)))->toBeFalse()
            ->and($real->isHostedBy($real->host))->toBeTrue();
    });

    it('does not let a demo\'s host act on another room', function () {
        $demo = demoGame();
        $other = demoGame(token: OTHER_DEMO_TOKEN);

        expect($other->isHostedBy(new DemoHost($demo->id)))->toBeFalse()->and($demo->isHostedBy(new DemoHost($demo->id)))->toBeTrue();
    });

    it('checks the host cookie again on every Livewire action', function () {
        $room = demoGame();
        $roomPlayer = $room->players()->where('is_bot', false)->first();
        $roomPlayer->forceFill(['left_at' => null])->save();

        demoHostPage(HostLobby::class, $room, null)->call('start')->assertForbidden();
        demoHostPage(HostLobby::class, $room, OTHER_DEMO_TOKEN)->call('closeRoom')->assertForbidden();
        demoHostPage(HostLobby::class, $room, null)->call('removePlayer', $roomPlayer->id)->assertForbidden();

        expect($room->fresh()->status)->toBe(RoomStatus::Lobby)->and($room->players()->count())->toBe(3);
    });

    it('lets the host start, remove a player and close the room through the screen', function () {
        $room = demoGame(humans: 2);
        $human = $room->players()->where('is_bot', false)->first();

        demoHostPage(HostLobby::class, $room)->call('removePlayer', $human->id)->assertHasNoErrors();
        expect($room->players()->where('is_bot', false)->count())->toBe(1);

        demoHostPage(HostLobby::class, $room)->call('start');
        expect($room->fresh()->status)->toBe(RoomStatus::Playing);
    });

    it('sends a closed demo back to the demo page, and a finished-but-never-played one too', function () {
        $room = demoGame();

        demoHostPage(HostLobby::class, $room)->call('closeRoom')->assertRedirect(route('demo'));
        expect($room->fresh()->status)->toBe(RoomStatus::Finished);
        demoHostPage(HostLobby::class, $room->fresh())->assertRedirect(route('demo'));
    });
});

describe('the demo lobby', function () {
    it('shows the Demo chip, the bots with a Bot tag and no remove button for them', function () {
        $room = demoGame();
        $room->players()->where('is_bot', false)->update(['left_at' => null]);

        $page = demoHostPage(HostLobby::class, $room);

        $page->assertSee('data-test="demo-chip"', false)->assertSee('Sara')->assertSee('Karim')->assertSee('data-test="demo-hint"', false);
        expect(substr_count($page->html(), 'data-test="bot-tag"'))->toBe(2)->and(substr_count($page->html(), 'data-test="remove-player"'))->toBe(1);
    });

    it('does not start without a real player, even though the bots are connected and ready', function () {
        $room = demoGame(humans: 0);

        $page = demoHostPage(HostLobby::class, $room);
        expect($page->html())->toContain('data-test="start-button"');
        $page->call('start')->assertSet('startError', GameStartException::noPlayers()->getMessage());

        expect($room->fresh()->status)->toBe(RoomStatus::Lobby);
        expect(fn () => app(GameEngine::class)->start($room, new DemoHost($room->id)))->toThrow(GameStartException::class);
    });

    it('unlocks Start when a real player is connected and ready, and not before they are ready', function () {
        $room = demoGame(humans: 1);
        $human = $room->players()->where('is_bot', false)->first();
        $human->forceFill(['is_ready' => false, 'left_at' => null])->save();

        expect(demoHostPage(HostLobby::class, $room)->viewData('canStart'))->toBeFalse();

        $human->forceFill(['is_ready' => true])->save();
        expect(demoHostPage(HostLobby::class, $room)->viewData('canStart'))->toBeTrue();
    });

    it('says "Waiting for you? Scan the code" after 90 seconds with nobody real, and keeps the bots ready', function () {
        $room = demoGame(humans: 0);
        $room->forceFill(['created_at' => now()])->save();

        expect(demoHostPage(HostLobby::class, $room)->viewData('waitingForYou'))->toBeFalse();

        $this->travel(89)->seconds();
        expect(demoHostPage(HostLobby::class, $room)->viewData('waitingForYou'))->toBeFalse();

        $this->travel(2)->seconds();
        $page = demoHostPage(HostLobby::class, $room);
        $page->assertSee('Waiting for you? Scan the code')->assertSee('data-test="waiting-for-you"', false);
        expect($page->viewData('waitingForYou'))->toBeTrue()->and($page->viewData('canStart'))->toBeFalse()
            ->and($room->players()->where('is_bot', true)->connected()->where('is_ready', true)->count())->toBe(2);
    });

    it('stops saying it once a real player has joined', function () {
        $room = demoGame(humans: 1);
        $room->players()->where('is_bot', false)->update(['left_at' => null]);
        $this->travel(5)->minutes();

        expect(demoHostPage(HostLobby::class, $room)->viewData('waitingForYou'))->toBeFalse();
    });

    it('does not say it in a real room', function () {
        $room = gameRoom(players: 0);
        $this->travel(5)->minutes();

        expect(Livewire::actingAs($room->host)->test(HostLobby::class, ['room' => $room])->viewData('waitingForYou'))->toBeFalse();
    });

    it('never drops a bot: the presence list is the truth for people, not for bots', function () {
        $room = demoGame(humans: 1);
        $human = $room->players()->where('is_bot', false)->first();

        app(RoomRoster::class)->syncPresence($room, [$human->id]);
        app(RoomRoster::class)->syncPresence($room, []);
        $this->travel(5)->minutes();
        app(RoomRoster::class)->pruneDisconnected($room);

        expect($room->players()->where('is_bot', true)->connected()->count())->toBe(2)->and($room->players()->where('is_bot', false)->count())->toBe(0);
    });

    it('lets the host remove a person but never a bot', function () {
        $room = demoGame();
        $bot = $room->players()->where('is_bot', true)->first();

        expect(app(RoomRoster::class)->remove($room, new DemoHost($room->id), $bot->id))->toBeFalse()->and($room->players()->where('is_bot', true)->count())->toBe(2);
    });
});

describe('the end of a demo', function () {
    it('offers an account or a friend\'s game instead of Play again', function () {
        $room = demoGame();
        startDemo($room);
        $room->roomQuestions()->update(['started_at' => now()->subMinute(), 'ends_at' => now()->subSeconds(40), 'revealed_at' => now()->subSeconds(39)]);
        $room->update(['status' => RoomStatus::Finished, 'finished_at' => now()]);
        $room->players()->where('is_bot', false)->update(['score' => 120]);

        $page = demoHostPage(HostResults::class, $room->fresh());

        $page->assertSee('data-test="host-your-own"', false)->assertSee('href="'.route('register').'"', false)
            ->assertSee('data-test="join-a-friend"', false)->assertSee('href="'.route('join').'"', false)
            ->assertDontSee('data-test="play-again"', false)->assertDontSee('data-test="new-game"', false)
            ->assertSee('Host your own game')->assertSee("Join a friend's game");
    });

    it('cannot be played again, by the screen or by the manager', function () {
        $room = demoGame();
        $room->update(['status' => RoomStatus::Finished, 'finished_at' => now()]);

        demoHostPage(HostResults::class, $room)->call('playAgain')->assertNoRedirect();
        expect(Room::count())->toBe(1)->and($room->fresh()->next_room_id)->toBeNull();

        $host = User::factory()->create();
        expect(fn () => app(RoomManager::class)->playAgain($room->forceFill(['host_id' => $host->id]), $host))->toThrow(LogicException::class, 'demo');
    });
});

describe('the presence channel', function () {
    it('lets the demo host join as the host, with no player id', function () {
        $room = demoGame();
        $request = demoRequest($room, DEMO_TOKEN);

        $host = (new RoomPresence(new PlayerIdentity($request)))->demoHostFor($request->input('channel_name'));
        $member = app(RoomPresence::class)->memberFor($room->code, $host);

        expect($host)->toBeInstanceOf(DemoHost::class)->and($host->getAuthIdentifier())->toBe('demo-host:'.$room->id)
            ->and($member)->toMatchArray(['room_player_id' => null, 'is_host' => true, 'locale' => 'en']);
    });

    it('lets in nobody else: no cookie, a wrong one, or a demo host of another room', function () {
        $room = demoGame();
        $other = demoGame(token: OTHER_DEMO_TOKEN);

        foreach ([null, OTHER_DEMO_TOKEN] as $token) {
            $request = demoRequest($room, $token);
            expect((new RoomPresence(new PlayerIdentity($request)))->demoHostFor($request->input('channel_name')))->toBeNull();
        }

        expect(app(RoomPresence::class)->memberFor($room->code, new DemoHost($other->id)))->toBeNull()
            ->and(app(RoomPresence::class)->memberFor($room->code, new DemoHost($room->id)))->not->toBeNull();
    });

    it('does not let a demo host token into a real room\'s channel', function () {
        $real = Room::factory()->create();

        expect(app(RoomPresence::class)->memberFor($real->code, new DemoHost($real->id)))->toBeNull();
        $request = demoRequest($real, DEMO_TOKEN);
        expect((new RoomPresence(new PlayerIdentity($request)))->demoHostFor($request->input('channel_name')))->toBeNull();
    });

    it('is a guard of the channel, after the player and web guards', function () {
        expect(config('auth.guards.demo'))->toBe(['driver' => 'demo']);
        Auth::guard('demo')->forgetUser();
    });
});
