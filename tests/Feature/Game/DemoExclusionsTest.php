<?php

use App\Enums\RoomStatus;
use App\Events\GameFinished;
use App\Events\GameStarted;
use App\Events\PlayerAnswered;
use App\Events\PlayerJoined;
use App\Events\QuestionRevealed;
use App\Events\QuestionStarted;
use App\Events\RoomClosed;
use App\Events\ScoreboardUpdated;
use App\Filament\Resources\Rooms\Pages\ListRooms;
use App\Filament\Widgets\ContentStats;
use App\Game\DemoStats;
use App\Game\GameStats;
use App\Game\PlayerStats;
use App\Game\SeenQuestions;
use App\Livewire\Host\HostLobby;
use App\Livewire\Host\HostResults;
use App\Livewire\Player\JoinRoom;
use App\Models\Admin;
use App\Models\PlayerAnswer;
use App\Models\Question;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\RoomQuestion;
use App\Models\User;
use App\Support\Analytics;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
 * What a demo (and so Sara and Karim) must never reach: question_seen, claimed rows, My games, the statistics and counts, Google Analytics's real-game events.
 * And what happens to a demo after two hours.
 */

beforeEach(function () {
    Queue::fake();
    Event::fake([GameStarted::class, QuestionStarted::class, PlayerAnswered::class, QuestionRevealed::class, ScoreboardUpdated::class, GameFinished::class, RoomClosed::class, PlayerJoined::class]);
    at('12:00:00.000');
});

describe('question_seen', function () {
    it('records the person who played a demo, never a bot', function () {
        $room = demoGame(questions: 2);
        // Even a bot that somehow carried a token would not be written.
        $room->players()->where('is_bot', true)->first()->forceFill(['guest_token' => str_repeat('b', 64)])->save();
        startDemo($room);

        app(SeenQuestions::class)->record($room->fresh(), Question::query()->value('id'));

        $rows = DB::table('question_seen')->get();
        expect($rows)->toHaveCount(1)->and($rows->first()->guest_token)->toBe($room->players()->where('is_bot', false)->value('guest_token'))
            ->and($rows->pluck('guest_token')->contains(str_repeat('b', 64)))->toBeFalse();
    });

    it('records nothing at all for a demo of bots', function () {
        $room = demoGame(humans: 0);
        app(SeenQuestions::class)->record($room, Question::factory()->create()->id);

        expect(DB::table('question_seen')->count())->toBe(0);
    });
});

describe('claiming', function () {
    it('never attaches a demo to an account, a bot to anyone, but still attaches a real game', function () {
        $demo = finishedDemo();
        $real = finishedGame(['Ali' => 200]);
        $real->players()->update(['guest_token' => DEMO_GUEST]);
        $user = User::factory()->create();

        $this->withCookie('lamma_guest', DEMO_GUEST)->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasNoErrors();

        expect($demo->players()->whereNotNull('user_id')->count())->toBe(0)->and($real->players()->where('user_id', $user->id)->count())->toBe(1)
            ->and(RoomPlayer::where('is_bot', true)->whereNotNull('user_id')->count())->toBe(0);
    });
});

describe('My games and the statistics', function () {
    it('shows a demo in no one\'s history, even for a row that is tied to an account', function () {
        $user = User::factory()->create();
        $demo = finishedDemo();
        $demo->players()->where('is_bot', false)->update(['user_id' => $user->id, 'guest_token' => null]);
        [$real] = savedGame($user, 300);

        $history = app(PlayerStats::class)->history($user);
        $totals = app(PlayerStats::class)->totals($user);

        expect($history->total())->toBe(1)->and($history->first()->room_id)->toBe($real->id)->and($totals['played'])->toBe(1);
    });

    it('leaves demos out of games per day, and counts the same game when it is real', function () {
        $demo = finishedDemo();
        $demo->update(['finished_at' => '2026-10-01 11:00:00']);
        expect(array_sum(app(GameStats::class)->gamesPerDay(30)))->toBe(0);

        $demo->update(['is_demo' => false]);
        expect(array_sum(app(GameStats::class)->gamesPerDay(30)))->toBe(1);
    });

    it('leaves demos and bots out of the live room counts', function () {
        demoGame(humans: 2);
        $real = gameRoom(players: 1);
        $real->players()->update(['left_at' => null]);
        $real->forceFill(['created_at' => now()])->save();

        $active = app(GameStats::class)->activeRooms();

        expect($active)->toBe(['lobby' => 1, 'playing' => 0, 'players' => 1]);
    });

    it('leaves demo answers out of the hardest questions and the times played', function () {
        $demo = finishedDemo(questions: 1);
        $roomQuestion = $demo->roomQuestions()->first();
        $wrong = $roomQuestion->question->options()->where('is_correct', false)->first();
        foreach ($demo->players as $player) {
            PlayerAnswer::create(['room_question_id' => $roomQuestion->id, 'room_player_id' => $player->id, 'question_option_id' => $wrong->id, 'answered_at' => now(), 'is_correct' => false, 'points' => 0]);
        }

        expect(app(GameStats::class)->hardestQuestionsQuery()->get())->toHaveCount(0)
            ->and(app(GameStats::class)->mostPlayedCategories()->count())->toBe(0);

        // The same answers in a real game count.
        $demo->update(['is_demo' => false]);
        $hardest = app(GameStats::class)->hardestQuestionsQuery()->get();
        expect($hardest)->toHaveCount(1)->and($hardest->first()->wrong_count)->toBe(3)->and(app(GameStats::class)->mostPlayedCategories()->count())->toBe(1);
    });

    it('leaves demos out of the admin\'s room count and shows the bots as no players', function () {
        Filament::setCurrentPanel('admin');
        $this->actingAs(Admin::factory()->create(), 'admin');
        $demo = demoGame(humans: 1);
        $real = gameRoom(players: 2);

        $stats = (fn () => $this->getStats())->call(Livewire::test(ContentStats::class)->instance());
        $rooms = collect($stats)->first(fn ($stat) => $stat->getLabel() === 'Rooms');
        expect($rooms->getValue())->toBe(1);

        Livewire::test(ListRooms::class)
            ->assertCanSeeTableRecords([$demo, $real])
            ->assertTableColumnStateSet('players_count', 1, $demo)
            ->assertTableColumnStateSet('players_count', 2, $real)
            ->assertSee('Demo (no account)');
    });
});

describe('Google Analytics', function () {
    function demoAnalyticsOn(): void
    {
        app()['env'] = 'production';
        config(['lamma.analytics.id' => 'G-ABC123DEF4']);
    }

    it('reports demo_started when a demo starts, after the redirect, with no parameters', function () {
        demoAnalyticsOn();
        demoContent();

        // Production brings CSRF back, so the form's token goes along.
        $this->withSession(['_token' => 'demo-token'])->post(route('demo.start'), ['_token' => 'demo-token'])->assertRedirect();

        expect(session('analytics'))->toBe([['name' => 'demo_started', 'params' => []]]);
    });

    it('stays silent about game_started when a demo starts', function () {
        demoAnalyticsOn();
        $room = demoGame();
        $room->players()->update(['left_at' => null]);

        demoHostPage(HostLobby::class, $room)->call('start')->assertNotDispatched('analytics');
        expect($room->fresh()->status)->toBe(RoomStatus::Playing);
    });

    it('stays silent about player_joined when someone joins a demo, and still says it for a real room', function () {
        demoAnalyticsOn();
        $demo = demoGame(humans: 0);
        $real = gameRoom(players: 0);

        Livewire::withCookie('lamma_guest', DEMO_GUEST)->test(JoinRoom::class)->set('code', $demo->code)->set('nickname', 'Sama')->set('language', 'en')->call('join')->assertHasNoErrors();
        expect(session('analytics', []))->toBe([]);

        Livewire::withCookie('lamma_guest', DEMO_GUEST)->test(JoinRoom::class)->set('code', $real->code)->set('nickname', 'Sama')->set('language', 'en')->call('join')->assertHasNoErrors();
        expect(array_column(session('analytics', []), 'name'))->toBe(['player_joined']);
    });

    it('reports demo_finished from a demo\'s end screen and game_finished, with a bucket and a mode, from a real one', function () {
        demoAnalyticsOn();
        $demo = finishedDemo();
        $real = finishedGame(['Ali' => 300, 'Sara' => 200, 'Hadi' => 100], questions: 3);

        $demoHtml = demoHostPage(HostResults::class, $demo)->html();
        expect($demoHtml)->toContain("window.lammaTrack('demo_finished', {}, 'demo-finished-{$demo->id}')")->and($demoHtml)->not->toContain('game_finished');

        $realHtml = Livewire::actingAs($real->host)->test(HostResults::class, ['room' => $real])->html();
        preg_match("/window\.lammaTrack\('game_finished'.*?\)\"/s", $realHtml, $call);
        expect($call[0])->toContain('\\u0022player_bucket\\u0022:\\u00222-4\\u0022')->and($call[0])->toContain('\\u0022mode\\u0022:\\u0022classic\\u0022')
            ->and($call[0])->toContain('\\u0022question_count\\u0022:3')->and($call[0])->not->toContain('demo');
    });

    it('buckets player counts', function (int $players, string $bucket) {
        expect(Analytics::playerBucket($players))->toBe($bucket);
    })->with([[0, '1'], [1, '1'], [2, '2-4'], [4, '2-4'], [5, '5-8'], [8, '5-8'], [9, '9+'], [40, '9+']]);

    it('only ever lets the funnel\'s own events through, with their parameters', function () {
        foreach (['demo_started', 'demo_finished', 'host_signup', 'rematch_clicked', 'join_page_viewed'] as $name) {
            expect(Analytics::event($name, ['nickname' => 'Sara', 'room' => 'ABCDEF']))->toBe(['name' => $name, 'params' => []]);
        }
        expect(Analytics::event('share_clicked', ['surface' => 'phone', 'method' => 'share', 'nickname' => 'Sara']))->toBe(['name' => 'share_clicked', 'params' => ['surface' => 'phone', 'method' => 'share']])
            ->and(Analytics::event('play_again_clicked'))->toBeNull();
    });
});

describe('after two hours', function () {
    it('closes and deletes a demo, with everything that hung on it', function () {
        $demo = finishedDemo();
        $demo->forceFill(['created_at' => now()->subMinutes(121)])->save();
        $real = gameRoom(players: 2);
        $real->forceFill(['created_at' => now()->subMinutes(121)])->save();
        $seen = DB::table('question_seen')->count();

        $this->artisan('lamma:prune')->expectsOutputToContain('Deleted 1 expired demo rooms.')->assertSuccessful();

        expect(Room::find($demo->id))->toBeNull()->and(RoomPlayer::where('room_id', $demo->id)->count())->toBe(0)->and(RoomQuestion::where('room_id', $demo->id)->count())->toBe(0)
            ->and(Room::find($real->id))->not->toBeNull()->and($real->players()->count())->toBe(2)
            ->and(DB::table('question_seen')->where('room_id', $demo->id)->count())->toBe(0);
    });

    it('keeps a demo that is younger than that, and tells the phones of one it closes', function () {
        $young = demoGame();
        $young->forceFill(['created_at' => now()->subMinutes(119)])->save();
        $old = demoGame();
        $old->forceFill(['created_at' => now()->subMinutes(121)])->save();

        $this->artisan('lamma:prune')->assertSuccessful();

        expect(Room::find($young->id))->not->toBeNull()->and(Room::find($old->id))->toBeNull();
        Event::assertDispatched(RoomClosed::class);
    });

    it('follows lamma.demo.lifetime_hours', function () {
        $demo = demoGame();
        $demo->forceFill(['created_at' => now()->subMinutes(61)])->save();
        $this->artisan('lamma:prune')->assertSuccessful();
        expect(Room::find($demo->id))->not->toBeNull();

        config(['lamma.demo.lifetime_hours' => 1]);
        $this->artisan('lamma:prune')->assertSuccessful();
        expect(Room::find($demo->id))->toBeNull();
    });

    it('tallies it first, for the dashboard: started, and finished if it was played to the end', function () {
        $played = finishedDemo();
        $played->forceFill(['created_at' => now()->subMinutes(125)])->save();
        $abandoned = demoGame();
        $abandoned->forceFill(['created_at' => now()->subMinutes(130)])->save();

        $this->artisan('lamma:prune')->assertSuccessful();

        $row = DB::table('demo_stats')->where('day', now()->toDateString())->first();
        expect((int) $row->started)->toBe(2)->and((int) $row->finished)->toBe(1)->and((int) $row->converted)->toBe(0);
    });

    it('counts rooms that are still alive in the summary, so nothing is missing for the first two hours', function () {
        finishedDemo();
        demoGame();
        $gone = demoGame();
        $gone->forceFill(['created_at' => now()->subHours(3)])->save();
        $this->artisan('lamma:prune')->assertSuccessful();

        expect(app(DemoStats::class)->summary(30))->toBe(['started' => 3, 'finished' => 1, 'converted' => 0]);
    });
});

describe('demo to signup', function () {
    it('counts a registration on a browser that holds a demo host cookie, and nothing else', function () {
        $user = User::factory()->create();

        app(Dispatcher::class)->dispatch(new Registered($user));
        expect(DB::table('demo_stats')->count())->toBe(0);

        app()->instance('request', Request::create('/register', 'POST', cookies: ['lamma_demo_host' => DEMO_TOKEN]));
        app(Dispatcher::class)->dispatch(new Registered($user));

        expect((int) DB::table('demo_stats')->where('day', now()->toDateString())->value('converted'))->toBe(1)->and(app(DemoStats::class)->summary(30)['converted'])->toBe(1);
    });

    it('does not count a malformed cookie', function () {
        app()->instance('request', Request::create('/register', 'POST', cookies: ['lamma_demo_host' => 'short']));
        app(Dispatcher::class)->dispatch(new Registered(User::factory()->create()));

        expect(DB::table('demo_stats')->count())->toBe(0);
    });
});
