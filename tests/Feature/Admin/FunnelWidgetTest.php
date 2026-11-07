<?php

use App\Events\GameStarted;
use App\Events\PlayerJoined;
use App\Events\QuestionStarted;
use App\Events\RoomClosed;
use App\Filament\Widgets\FunnelOverview;
use App\Game\Bots;
use App\Game\GameStats;
use App\Livewire\Host\HostResults;
use App\Models\Admin;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Support\Analytics;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
 * The "Funnel" widget on the dashboard (rooms created, games started and finished, rematch rate, average players, the demo as its own figure) is computed from the
 * database, not from Google, over the last 30 days, with demo games and bots left out of the totals. And the events behind the funnel that reach Google.
 */

beforeEach(function () {
    Queue::fake();
    Event::fake([GameStarted::class, QuestionStarted::class, RoomClosed::class, PlayerJoined::class]);
    Filament::setCurrentPanel('admin');
    $this->actingAs(Admin::factory()->create(), 'admin');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
});

/** The widget's stats as [label => [value, description]]. */
function funnelStats(): array
{
    $stats = (fn () => $this->getStats())->call(Livewire::test(FunnelOverview::class)->instance());

    return collect($stats)->mapWithKeys(fn ($stat) => [(string) $stat->getLabel() => [$stat->getValue(), (string) $stat->getDescription()]])->all();
}

/** Real games of the last days: A is played to the end and replaced by B (a rematch), B is under way, C is a lobby that never started. */
function funnelRooms(): array
{
    $a = finishedGame(['Ali' => 300, 'Sara' => 200, 'Hadi' => 100], questions: 3);
    $b = gameRoom(players: 2);
    startGame($b);
    $c = gameRoom(players: 1);
    $a->update(['next_room_id' => $b->id]);

    return [$a, $b, $c];
}

describe('the numbers', function () {
    it('counts rooms created, games started, games finished, the rematch rate and the average players', function () {
        funnelRooms();

        $funnel = app(GameStats::class)->funnel(30);

        expect($funnel)->toMatchArray(['created' => 3, 'started' => 2, 'finished' => 1, 'rematched' => 1, 'rematch_rate' => 100, 'average_players' => 2.5]);
    });

    it('looks only at the last 30 days, today included', function () {
        [$a] = funnelRooms();
        $old = gameRoom(players: 2);
        startGame($old);
        $old->forceFill(['created_at' => now()->subDays(40)])->save();
        $edge = gameRoom(players: 2);
        $edge->forceFill(['created_at' => now()->subDays(29)->startOfDay()])->save();
        $justOut = gameRoom(players: 2);
        $justOut->forceFill(['created_at' => now()->subDays(30)->endOfDay()])->save();

        expect(app(GameStats::class)->funnel(30)['created'])->toBe(4)->and(app(GameStats::class)->funnel(7)['created'])->toBe(3);
    });

    it('leaves demo games and the bots out of every total, and shows the demo as a figure of its own', function () {
        funnelRooms();
        $demo = finishedDemo();
        demoGame();
        Room::where('is_demo', false)->first()->players()->create(['nickname' => 'Bot', 'locale' => 'en', 'is_bot' => true, 'joined_at' => now(), 'is_ready' => true]);

        $funnel = app(GameStats::class)->funnel(30);

        // The same real numbers as without the demos: 3 rooms, 2 started, 1 finished, and the bot is not a player.
        expect($funnel)->toMatchArray(['created' => 3, 'started' => 2, 'finished' => 1, 'average_players' => 2.5])
            ->and($funnel['demo'])->toBe(['started' => 2, 'finished' => 1, 'converted' => 0]);
    });

    it('adds what lamma:prune tallied before it deleted the old demos, and the signups that followed', function () {
        DB::table('demo_stats')->insert(['day' => '2026-10-14', 'started' => 5, 'finished' => 3, 'converted' => 1]);
        DB::table('demo_stats')->insert(['day' => '2026-08-01', 'started' => 99, 'finished' => 99, 'converted' => 99]);
        finishedDemo();

        expect(app(GameStats::class)->funnel(30)['demo'])->toBe(['started' => 6, 'finished' => 4, 'converted' => 1]);
    });

    it('has nothing to divide by on an empty site, and says so', function () {
        $funnel = app(GameStats::class)->funnel(30);

        expect($funnel)->toMatchArray(['created' => 0, 'started' => 0, 'finished' => 0, 'rematch_rate' => null, 'average_players' => null])
            ->and(GameStats::share(1, 0))->toBeNull()->and(GameStats::share(1, 3))->toBe(33);
    });
});

describe('the widget', function () {
    it('shows each figure with what it is a share of', function () {
        funnelRooms();
        finishedDemo();

        $stats = funnelStats();

        expect($stats['Rooms created'][0])->toBe(3)
            ->and($stats['Games started'][0])->toBe(2)->and($stats['Games started'][1])->toBe('67% of rooms created')
            ->and($stats['Games finished'][0])->toBe(1)->and($stats['Games finished'][1])->toBe('50% of games started')
            ->and($stats['Rematch rate'][0])->toBe('100%')->and($stats['Rematch rate'][1])->toBe('1 of 1 finished games were played again')
            ->and($stats['Average players'][0])->toBe(2.5)
            ->and($stats['Demo games'][0])->toBe(1)->and($stats['Demo games'][1])->toContain('1 played to the end');
    });

    it('is honest about an empty site', function () {
        $stats = funnelStats();

        expect($stats['Rooms created'][0])->toBe(0)->and($stats['Games started'][1])->toBe('nothing to compare yet')->and($stats['Rematch rate'][0])->toBe('–')->and($stats['Average players'][0])->toBe('–');
    });

    it('is on the dashboard, under the live rooms', function () {
        $this->get('/admin')->assertOk()->assertSeeLivewire(FunnelOverview::class)->assertSee('Funnel');
        expect((new ReflectionProperty(FunnelOverview::class, 'sort'))->getValue())->toBe(2);
    });

    it('does not need Google: it works with analytics off and never mentions an ID', function () {
        expect(Analytics::enabled())->toBeFalse();
        funnelRooms();

        expect(funnelStats()['Rooms created'][0])->toBe(3)
            ->and(file_get_contents(app_path('Filament/Widgets/FunnelOverview.php')))->not->toContain('Analytics::')->not->toContain('gtag');
    });
});

describe('the events that reach Google', function () {
    function funnelAnalyticsOn(): void
    {
        app()['env'] = 'production';
        config(['lamma.analytics.id' => 'G-ABC123DEF4']);
    }

    /** Register from this address: a browser that was heading to $intended (or holds a demo host cookie). */
    function registerHeading(?string $intended, ?string $demoCookie = null): array
    {
        $request = test()->withSession(['_token' => 't', ...($intended ? ['url.intended' => $intended] : [])]);
        if ($demoCookie) {
            $request = $request->withCookie('lamma_demo_host', $demoCookie);
        }
        $request->post('/register', ['_token' => 't', 'name' => 'New Host', 'email' => 'new@example.com', 'password' => 'Str0ng!Passw0rd-here', 'password_confirmation' => 'Str0ng!Passw0rd-here']);

        return array_column(session('analytics', []), 'name');
    }

    it('reports host_signup, as well as sign_up, when someone signs up on the way to hosting', function () {
        funnelAnalyticsOn();

        expect(registerHeading(route('rooms.create')))->toBe(['sign_up', 'host_signup']);
    });

    it('reports host_signup for a browser that has just hosted a demo', function () {
        funnelAnalyticsOn();

        expect(registerHeading(null, DEMO_TOKEN))->toBe(['sign_up', 'host_signup']);
    });

    it('reports only sign_up for anyone else', function () {
        funnelAnalyticsOn();

        expect(registerHeading(null))->toBe(['sign_up'])->and(session('analytics')[0]['params'])->toBe([]);
    });

    it('lists every event of the funnel, each with no name, code or account in it', function () {
        foreach (['demo_started', 'demo_finished', 'host_signup', 'room_created', 'game_started', 'game_finished', 'rematch_clicked', 'share_clicked', 'join_page_viewed'] as $name) {
            expect(Analytics::EVENTS)->toHaveKey($name);
            $event = Analytics::event($name, ['nickname' => 'Sara', 'code' => 'K7MP9Z', 'email' => 'a@b.c', 'user_id' => 4, 'token' => str_repeat('a', 64), 'room' => 'x']);
            expect(array_keys($event['params']))->each->not->toBeIn(['nickname', 'code', 'email', 'user_id', 'token', 'room']);
        }
        expect(Analytics::EVENTS['game_finished'])->toContain('player_bucket')->toContain('mode')->toContain('question_count')->and(Analytics::EVENTS['share_clicked'])->toBe(['surface', 'method']);
    });

    it('reports join_page_viewed from the join page, once per browser session, and a demo page with the consent notice', function () {
        funnelAnalyticsOn();

        $join = $this->get('/join')->assertOk()->getContent();
        $demo = $this->get('/demo')->assertOk()->getContent();

        expect($join)->toContain("window.lammaTrack('join_page_viewed', {}, 'join-page')")->and($demo)->toContain('data-test="consent-banner"');
    });

    it('counts players in buckets, bots not among them', function () {
        funnelAnalyticsOn();
        $room = finishedGame(['Ali' => 300, 'Maya' => 200], questions: 3);
        app(Bots::class)->seed($room);
        $room->update(['is_demo' => false]);

        $html = Livewire::actingAs($room->host)->test(HostResults::class, ['room' => $room])->html();

        expect($html)->toContain('player_bucket')->and($html)->toContain('2-4')->and(RoomPlayer::where('room_id', $room->id)->count())->toBe(4);
    });
});
