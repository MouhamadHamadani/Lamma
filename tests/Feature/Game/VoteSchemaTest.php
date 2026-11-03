<?php

use App\Enums\GameMode;
use App\Enums\VoteOutcome;
use App\Models\Category;
use App\Models\CategoryVote;
use App\Models\CategoryVoteRound;
use App\Models\Room;
use App\Models\RoomPlayer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

describe('vote mode schema', function () {
    it('makes a new room, and a room saved before vote mode existed, a Classic room with no vote setup', function () {
        $room = Room::factory()->create();
        $id = DB::table('rooms')->insertGetId([
            'code' => 'OLDRM1', 'host_id' => $room->host_id, 'status' => 'finished', 'settings' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([$room->fresh(), Room::find($id)] as $classic) {
            expect($classic->game_mode)->toBe(GameMode::Classic)
                ->and($classic->isVote())->toBeFalse()
                ->and($classic->vote_every)->toBeNull()
                ->and($classic->first_category_id)->toBeNull()
                ->and($classic->voteSettings())->toBeNull();
        }
    });

    it('keeps the vote setup: mode, first category, pool and vote_every', function () {
        [$first, $second, $third] = Category::factory()->count(3)->create();
        $room = Room::factory()->vote(every: 3)->create(['first_category_id' => $first->id]);
        $room->pool()->attach([$first->id, $second->id, $third->id]);

        $settings = $room->fresh()->voteSettings();

        expect($room->fresh()->game_mode)->toBe(GameMode::Vote)
            ->and($settings->firstCategoryId)->toBe($first->id)
            ->and($settings->poolIds)->toBe([$first->id, $second->id, $third->id])
            ->and($settings->every)->toBe(3)
            ->and($room->firstCategory->is($first))->toBeTrue();
    });

    it('stores a round with its offered order, deadline in milliseconds, winner and outcome', function () {
        [$a, $b] = Category::factory()->count(2)->create();
        $room = Room::factory()->vote()->create();
        $round = CategoryVoteRound::factory()->for($room)->create([
            'round' => 2, 'option_ids' => [$b->id, $a->id], 'ends_at' => now()->setTime(12, 0, 10, 250_000),
        ]);
        $round->update(['closed_at' => now(), 'winner_category_id' => $b->id, 'outcome' => VoteOutcome::Tie]);

        $fresh = $round->fresh();
        expect($fresh->option_ids)->toBe([$b->id, $a->id])
            ->and($fresh->ends_at->format('H:i:s.v'))->toBe('12:00:10.250')
            ->and($fresh->winner->is($b))->toBeTrue()
            ->and($fresh->outcome)->toBe(VoteOutcome::Tie)
            ->and($fresh->isOpen())->toBeFalse()
            ->and($room->voteRounds()->pluck('round')->all())->toBe([2]);
    });

    it('allows one vote per player per round', function () {
        $room = Room::factory()->vote()->create();
        $player = RoomPlayer::factory()->for($room)->create();
        $category = Category::factory()->create();
        $round = CategoryVoteRound::factory()->for($room)->create(['option_ids' => [$category->id]]);

        CategoryVote::create(['room_id' => $room->id, 'round' => 1, 'room_player_id' => $player->id, 'category_id' => $category->id]);
        CategoryVote::create(['room_id' => $room->id, 'round' => 2, 'room_player_id' => $player->id, 'category_id' => $category->id]);

        expect(fn () => CategoryVote::create(['room_id' => $room->id, 'round' => 1, 'room_player_id' => $player->id, 'category_id' => $category->id]))
            ->toThrow(UniqueConstraintViolationException::class)
            ->and($round->votes()->count())->toBe(1);
    });

    it('takes the vote data with the room when the room is deleted', function () {
        $category = Category::factory()->create();
        $room = Room::factory()->vote()->create(['first_category_id' => $category->id]);
        $room->pool()->attach($category);
        CategoryVoteRound::factory()->for($room)->create(['option_ids' => [$category->id]]);
        CategoryVote::factory()->create(['room_id' => $room->id, 'room_player_id' => RoomPlayer::factory()->for($room), 'category_id' => $category->id]);

        $room->delete();

        expect(DB::table('room_category_pool')->count())->toBe(0)
            ->and(CategoryVoteRound::count())->toBe(0)
            ->and(CategoryVote::count())->toBe(0);
    });

    it('rolls back and forward cleanly, leaving Classic rooms as they were', function () {
        $migration = require database_path('migrations/2026_11_03_090000_add_vote_mode.php');
        $room = Room::factory()->create();

        $migration->down();
        expect(Schema::hasColumns('rooms', ['game_mode', 'vote_every', 'first_category_id']))->toBeFalse()
            ->and(Schema::hasTable('category_votes'))->toBeFalse()
            ->and(DB::table('rooms')->where('id', $room->id)->exists())->toBeTrue();

        $migration->up();
        expect(Schema::hasColumns('rooms', ['game_mode', 'vote_every', 'first_category_id']))->toBeTrue()
            ->and(Schema::hasTable('room_category_pool'))->toBeTrue()
            ->and(Schema::hasTable('category_vote_rounds'))->toBeTrue()
            ->and($room->fresh()->game_mode)->toBe(GameMode::Classic);
    });
});
