<?php

use App\Enums\GameMode;
use App\Enums\HostScreenLocale;
use App\Enums\RoomStatus;
use App\Filament\Resources\Rooms\Pages\ListRooms;
use App\Filament\Resources\Rooms\Pages\ViewRoom;
use App\Filament\Widgets\ContentStats;
use App\Game\GameEngine;
use App\Game\GameStats;
use App\Livewire\Host\HostLobby;
use App\Livewire\Player\MyGames;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Event::fake();
    Queue::fake();
    at('12:00:00');
});

/**
 * A finished Vote game (10 questions, a vote after 5) with a pool of four named categories, played by $user. The vote goes to its first option.
 *
 * @return array{0: Room, 1: list<Category>, 2: int} the room, the pool (the first category first) and the winner's id
 */
function finishedVoteGame(User $user): array
{
    $pool = collect(['Alpha Topic', 'Beta Topic', 'Gamma Topic', 'Delta Topic'])
        ->map(fn (string $name) => categoryWithQuestions(6, category: ['name' => ['en' => $name, 'ar' => $name.' ع']]))->all();
    $room = voteRoom(players: 0, questions: 10, every: 5, categories: $pool);
    $me = RoomPlayer::factory()->for($room)->forUser($user)->create(['is_ready' => true, 'left_at' => null, 'locale' => 'en']);
    $winner = null;

    $room = finishVoteGame($room, function ($vote) use ($me, &$winner) {
        $winner = $vote->option_ids[0];
        app(GameEngine::class)->castVote($me, $winner);
    });

    return [$room, $pool, $winner];
}

describe('what a Vote game was about', function () {
    it('lists the categories that were played (the first and the winners), not the pool', function () {
        $user = User::factory()->create(['preferred_locale' => 'en']);
        [$room, $pool, $winner] = finishedVoteGame($user);
        $notPlayed = collect($pool)->reject(fn (Category $category) => in_array($category->id, [$pool[0]->id, $winner], true));

        expect($room->isCompleted())->toBeTrue()
            ->and($room->categoryIds())->toEqualCanonicalizing([$pool[0]->id, $winner]);

        $page = Livewire::actingAs($user)->test(MyGames::class)->assertSee('Alpha Topic')->assertSee(Category::find($winner)->name);
        foreach ($notPlayed as $category) {
            $page->assertDontSee($category->name);
        }
    });

    it('keeps a Classic game\'s chosen categories', function () {
        $room = gameRoom();

        expect($room->categoryIds())->toBe($room->settings->categoryIds);
    });

    it('shows the first category on the lobby chip', function () {
        $first = categoryWithQuestions(6, category: ['name' => ['en' => 'Opening Topic', 'ar' => 'البداية']]);
        $room = voteRoom(categories: [$first, categoryWithQuestions(6), categoryWithQuestions(6)], settings: ['hostScreenLocale' => HostScreenLocale::En]);

        Livewire::actingAs($room->host)->test(HostLobby::class, ['room' => $room])->assertSee('Vote · starts with Opening Topic');
    });
});

describe('the admin', function () {
    beforeEach(function () {
        $this->actingAs(Admin::factory()->create(), 'admin');
    });

    it('counts the categories actually played in Vote games for the most-played chart', function () {
        [$room, $pool, $winner] = finishedVoteGame(User::factory()->create());
        Auth::shouldUse('admin');

        $counts = app(GameStats::class)->mostPlayedCategories()->pluck('room_questions_count', 'id');

        expect($counts->all())->toEqualCanonicalizing([$pool[0]->id => 5, $winner => 5]);
    });

    it('shows and filters the mode on the rooms list, and the vote setup on a room', function () {
        $classic = Room::factory()->create();
        [$a, $b, $c] = collect(range(1, 3))->map(fn () => categoryWithQuestions(6))->all();
        $vote = Room::factory()->vote(every: 3)->create(['first_category_id' => $a->id]);
        $vote->pool()->attach([$a->id, $b->id, $c->id]);

        Livewire::test(ListRooms::class)
            ->assertCanSeeTableRecords([$classic, $vote])
            ->assertTableColumnExists('game_mode')
            ->filterTable('game_mode', GameMode::Vote->value)
            ->assertCanSeeTableRecords([$vote])
            ->assertCanNotSeeTableRecords([$classic]);

        Livewire::test(ViewRoom::class, ['record' => $vote->getRouteKey()])
            ->assertSee('Vote pool')->assertSee($b->name)->assertSee('3 questions');
    });

    it('counts the Vote games on the dashboard', function () {
        Room::factory()->count(2)->create();
        Room::factory()->vote()->create();

        Livewire::test(ContentStats::class)->assertSee('1 in Vote mode');
    });
});

describe('the hourly clean-up', function () {
    it('treats an unfinished Vote room like any other: an old lobby is closed, a game in a vote is left, an old one loses its photos', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
        $lobby = voteRoom(players: 1);
        Room::query()->whereKey($lobby->id)->update(['created_at' => now()->subHours(7)]);

        $voting = voteRoom(players: 1, questions: 10, every: 5);
        playToVote($voting);
        $player = $voting->players()->first();
        photos()->set($player, upload(jpegBytes()));
        Room::query()->whereKey($voting->id)->update(['created_at' => now()->subHours(30)]);

        $this->artisan('lamma:prune')->assertSuccessful();

        expect($lobby->fresh()->status)->toBe(RoomStatus::Finished)
            ->and($voting->fresh()->status)->toBe(RoomStatus::Playing)
            ->and(votes()->openRound($voting->fresh()))->not->toBeNull()
            ->and($player->fresh()->avatar_path)->toBeNull();
    });
});
