<?php

use App\Events\PlayerJoined;
use App\Events\PlayerUpdated;
use App\Filament\Widgets\ContentStats;
use App\Game\JoinException;
use App\Game\NicknameRefusals;
use App\Game\RoomJoiner;
use App\Game\RoomRoster;
use App\Models\Admin;
use App\Models\RoomPlayer;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/*
 * Phase 14.1: a small figure on the admin dashboard, "Nicknames refused today", with the last 7 days beside it. It is a COUNT per day (the nickname_refusals table):
 * never the word, never who tried.
 */

beforeEach(function () {
    Queue::fake();
    Event::fake([PlayerJoined::class, PlayerUpdated::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
});

function refused(string $name, ?RoomPlayer $rename = null): void
{
    try {
        if ($rename) {
            app(RoomRoster::class)->rename($rename, $name);
        } else {
            app(RoomJoiner::class)->join(gameRoom(players: 0)->code, $name, 'en');
        }
    } catch (JoinException) {
        // the point of the test
    }
}

describe('the tally', function () {
    it('counts a refused join and a refused rename, one per refusal', function () {
        refused('Big Dick');
        refused('f u c k');
        $player = RoomPlayer::factory()->for(gameRoom(players: 0))->create(['nickname' => 'Hadi', 'left_at' => null]);
        refused('B1tch', $player);

        expect(app(NicknameRefusals::class)->today())->toBe(3)->and(DB::table('nickname_refusals')->count())->toBe(1);
    });

    it('does not count anything else: a good name, a taken one, a blocked name only DISPLAYED as Player', function () {
        $room = gameRoom(players: 0);
        RoomPlayer::factory()->for($room)->create(['nickname' => 'Hadi', 'left_at' => null]);

        app(RoomJoiner::class)->join($room->code, 'Cassandra', 'en');
        try {
            app(RoomJoiner::class)->join($room->code, 'hadi', 'en');
        } catch (JoinException) {
        }
        RoomPlayer::factory()->for($room)->create(['nickname' => 'Fuckface', 'left_at' => null])->nickname;   // the stored name the list refuses today: masked on screen

        expect(app(NicknameRefusals::class)->today())->toBe(0)->and(DB::table('nickname_refusals')->count())->toBe(0);
    });

    it('is one row a day, however often it is called', function () {
        foreach (range(1, 5) as $n) {
            app(NicknameRefusals::class)->record();
        }

        expect(DB::table('nickname_refusals')->count())->toBe(1)->and((int) DB::table('nickname_refusals')->value('refused'))->toBe(5);

        $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00:00'));
        app(NicknameRefusals::class)->record();

        expect(DB::table('nickname_refusals')->count())->toBe(2)->and(app(NicknameRefusals::class)->today())->toBe(1);
    });

    it('adds up the last 7 days, today included, and nothing older', function () {
        foreach ([0 => 4, 1 => 3, 3 => 2, 6 => 1, 7 => 50, 30 => 100] as $daysAgo => $count) {
            DB::table('nickname_refusals')->insert(['day' => now()->subDays($daysAgo)->toDateString(), 'refused' => $count]);
        }

        $refusals = app(NicknameRefusals::class);

        expect($refusals->today())->toBe(4)->and($refusals->lastDays(7))->toBe(4 + 3 + 2 + 1)->and($refusals->lastDays(2))->toBe(7)->and($refusals->lastDays(0))->toBe(4);
    });

    it('keeps no word, no name, no room and no address: only a day and a number', function () {
        refused('Big Dick');
        refused('Fuckface');

        $rows = DB::table('nickname_refusals')->get();

        expect(Schema::getColumnListing('nickname_refusals'))->toBe(['day', 'refused'])
            ->and(strtolower(json_encode($rows)))->not->toContain('dick')->not->toContain('fuck')->and($rows)->toHaveCount(1)->and((int) $rows->first()->refused)->toBe(2);
    });
});

describe('the dashboard', function () {
    beforeEach(function () {
        Filament::setCurrentPanel('admin');
        $this->actingAs(Admin::factory()->create(), 'admin');
    });

    it('shows the figure beside the other counts, with the week under it', function () {
        refused('Big Dick');
        refused('f u c k');
        DB::table('nickname_refusals')->insert(['day' => now()->subDays(2)->toDateString(), 'refused' => 5]);

        $stats = (fn () => $this->getStats())->call(Livewire::test(ContentStats::class)->instance());
        $byLabel = collect($stats)->mapWithKeys(fn ($stat) => [(string) $stat->getLabel() => [$stat->getValue(), (string) $stat->getDescription()]]);

        expect($byLabel['Nicknames refused today'])->toBe([2, '7 in the last 7 days']);
        Livewire::test(ContentStats::class)->assertSee('Nicknames refused today')->assertSee('7 in the last 7 days')->assertDontSee('Dick');
    });

    it('shows a plain zero when nothing was refused', function () {
        Livewire::test(ContentStats::class)->assertSee('Nicknames refused today')->assertSee('0 in the last 7 days');
    });
});
