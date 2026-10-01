<?php

use App\Enums\RoomStatus;
use App\Game\RoomRoster;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

function roster(): RoomRoster
{
    return app(RoomRoster::class);
}

describe('ready', function () {
    it('saves Ready on and off', function () {
        $player = RoomPlayer::factory()->for(Room::factory()->create())->create(['is_ready' => false]);

        expect(roster()->toggleReady($player))->toBeTrue()->and($player->fresh()->is_ready)->toBeTrue();
        expect(roster()->toggleReady($player->fresh()))->toBeTrue()->and($player->fresh()->is_ready)->toBeFalse();
    });

    it('only changes the lobby', function (string $state) {
        $player = RoomPlayer::factory()->for(Room::factory()->{$state}()->create())->create(['is_ready' => false]);

        expect(roster()->setReady($player, true))->toBeFalse()->and($player->fresh()->is_ready)->toBeFalse();
    })->with(['playing', 'finished']);

    it('reports no change when it is already set', function () {
        $player = RoomPlayer::factory()->for(Room::factory()->create())->create(['is_ready' => true]);

        expect(roster()->setReady($player, true))->toBeFalse();
    });
});

describe('leaving', function () {
    it('removes the player\'s own row only', function () {
        $room = Room::factory()->create();
        $me = RoomPlayer::factory()->for($room)->create();
        $other = RoomPlayer::factory()->for($room)->create();

        expect(roster()->leave($me))->toBeTrue();

        expect(RoomPlayer::find($me->id))->toBeNull()->and(RoomPlayer::find($other->id))->not->toBeNull();
    });

    it('is only possible in the lobby', function () {
        $player = RoomPlayer::factory()->for(Room::factory()->playing()->create())->create();

        expect(roster()->leave($player))->toBeFalse()->and(RoomPlayer::find($player->id))->not->toBeNull();
    });

    it('frees the nickname for someone else', function () {
        $room = Room::factory()->create();
        $player = RoomPlayer::factory()->for($room)->create(['nickname' => 'Sara']);
        roster()->leave($player);

        expect(RoomPlayer::factory()->for($room)->create(['nickname' => 'Sara'])->exists)->toBeTrue();
    });
});

describe('the host removing a player', function () {
    it('deletes that player', function () {
        $room = Room::factory()->create();
        $player = RoomPlayer::factory()->for($room)->create();

        expect(roster()->remove($room, $room->host, $player->id))->toBeTrue()->and(RoomPlayer::find($player->id))->toBeNull();
    });

    it('is refused for anyone but the host', function () {
        $room = Room::factory()->create();
        $player = RoomPlayer::factory()->for($room)->create();

        expect(fn () => roster()->remove($room, User::factory()->create(), $player->id))->toThrow(AuthorizationException::class);
        expect(RoomPlayer::find($player->id))->not->toBeNull();
    });

    it('cannot reach a player of another room', function () {
        $room = Room::factory()->create();
        $stranger = RoomPlayer::factory()->for(Room::factory()->create())->create();

        expect(roster()->remove($room, $room->host, $stranger->id))->toBeFalse()->and(RoomPlayer::find($stranger->id))->not->toBeNull();
    });

    it('treats a player who is already gone as nothing to do', function () {
        $room = Room::factory()->create();

        expect(roster()->remove($room, $room->host, 99999))->toBeFalse();
    });

    it('is only possible in the lobby', function () {
        $room = Room::factory()->playing()->create();
        $player = RoomPlayer::factory()->for($room)->create();

        expect(roster()->remove($room, $room->host, $player->id))->toBeFalse();
    });
});

describe('presence', function () {
    it('marks a player disconnected, keeping the first moment', function () {
        $room = Room::factory()->create();
        $player = RoomPlayer::factory()->for($room)->create();

        roster()->markDisconnected($room, $player->id);
        $first = $player->fresh()->left_at;
        $this->travel(10)->seconds();
        roster()->markDisconnected($room, $player->id);

        expect($first)->not->toBeNull()->and($player->fresh()->left_at->equalTo($first))->toBeTrue();
    });

    it('clears left_at when the player is back', function () {
        $room = Room::factory()->create();
        $player = RoomPlayer::factory()->for($room)->create(['left_at' => now()->subSeconds(5)]);

        roster()->markConnected($room, $player->id);

        expect($player->fresh()->left_at)->toBeNull();
    });

    it('never touches a player of another room', function () {
        $room = Room::factory()->create();
        $stranger = RoomPlayer::factory()->for(Room::factory()->create())->create(['left_at' => now()->subSeconds(5)]);

        roster()->markConnected($room, $stranger->id);
        roster()->markDisconnected($room, RoomPlayer::factory()->for(Room::factory()->create())->create()->id);

        expect($stranger->fresh()->left_at)->not->toBeNull();
    });

    it('syncs the whole room from the channel\'s member list', function () {
        $room = Room::factory()->create();
        $here = RoomPlayer::factory()->for($room)->create(['left_at' => now()->subSeconds(5)]);
        $gone = RoomPlayer::factory()->for($room)->create(['left_at' => null]);
        $stillGone = RoomPlayer::factory()->for($room)->create(['left_at' => now()->subSeconds(20)]);

        roster()->syncPresence($room, [$here->id]);

        expect($here->fresh()->left_at)->toBeNull()
            ->and($gone->fresh()->left_at)->not->toBeNull()
            ->and($stillGone->fresh()->left_at->lt(now()->subSeconds(10)))->toBeTrue(); // its clock was not restarted
    });

    it('treats an empty member list as nobody connected', function () {
        $room = Room::factory()->create();
        RoomPlayer::factory()->for($room)->count(2)->create();

        roster()->syncPresence($room, []);

        expect($room->players()->connected()->count())->toBe(0);
    });
});

describe('dropping players who stay disconnected', function () {
    it('keeps them for 30 seconds and drops them after', function () {
        $room = Room::factory()->create();
        $recent = RoomPlayer::factory()->for($room)->create(['left_at' => now()->subSeconds(29)]);
        $old = RoomPlayer::factory()->for($room)->create(['left_at' => now()->subSeconds(31)]);
        $connected = RoomPlayer::factory()->for($room)->create(['left_at' => null]);

        expect(roster()->pruneDisconnected($room))->toBe(1);

        expect(RoomPlayer::find($old->id))->toBeNull()
            ->and(RoomPlayer::find($recent->id))->not->toBeNull()
            ->and(RoomPlayer::find($connected->id))->not->toBeNull();
    });

    it('drops a player once the clock passes 30 seconds', function () {
        $room = Room::factory()->create();
        $player = RoomPlayer::factory()->for($room)->create(['left_at' => now()]);

        roster()->pruneDisconnected($room);
        expect(RoomPlayer::find($player->id))->not->toBeNull();

        $this->travel(31)->seconds();
        roster()->pruneDisconnected($room);

        expect(RoomPlayer::find($player->id))->toBeNull();
    });

    it('does not drop someone who came back in time', function () {
        $room = Room::factory()->create();
        $player = RoomPlayer::factory()->for($room)->create(['left_at' => now()->subSeconds(25)]);

        roster()->markConnected($room, $player->id);
        $this->travel(60)->seconds();

        expect(roster()->pruneDisconnected($room))->toBe(0)->and(RoomPlayer::find($player->id))->not->toBeNull();
    });

    it('only happens in the lobby, and only in this room', function () {
        $playing = Room::factory()->playing()->create();
        $inPlaying = RoomPlayer::factory()->for($playing)->create(['left_at' => now()->subMinutes(5)]);
        $room = Room::factory()->create();
        $elsewhere = RoomPlayer::factory()->for(Room::factory()->create())->create(['left_at' => now()->subMinutes(5)]);

        expect(roster()->pruneDisconnected($playing))->toBe(0)->and(roster()->pruneDisconnected($room))->toBe(0);

        expect(RoomPlayer::find($inPlaying->id))->not->toBeNull()->and(RoomPlayer::find($elsewhere->id))->not->toBeNull();
        expect($playing->status)->toBe(RoomStatus::Playing);
    });
});
