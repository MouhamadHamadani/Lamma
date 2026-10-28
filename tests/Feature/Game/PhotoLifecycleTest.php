<?php

use App\Actions\DeleteAccount;
use App\Enums\RoomStatus;
use App\Events\PlayerLeft;
use App\Events\PlayerUpdated;
use App\Events\RoomClosed;
use App\Events\RoomRestarted;
use App\Game\Housekeeping;
use App\Game\PlayerPhoto;
use App\Game\RoomManager;
use App\Game\RoomRoster;
use App\Livewire\Player\MyGames;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

const LIFE_TOKEN = 'l1f3000000000000000000000000000000000000000000000000000000000000';

beforeEach(function () {
    Event::fake([RoomClosed::class, RoomRestarted::class, PlayerLeft::class, PlayerUpdated::class]);
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
});

/** Every file the photo disk holds. */
function storedPhotos(): array
{
    return Storage::disk(PlayerPhoto::DISK)->allFiles();
}

/** A room in the given state with $players players who each have a selfie. */
function roomWithSelfies(array $room = [], int $players = 2): Room
{
    $room = Room::factory()->create($room);
    RoomPlayer::factory()->for($room)->count($players)->create()->each(fn (RoomPlayer $player) => photos()->set($player, upload(jpegBytes(120, 120))));

    return $room;
}

describe('Play again', function () {
    /** A finished game with settings that can be played again, and $players selfie-taking connected players. */
    function rematchSetup(array $settings = [], int $players = 2): Room
    {
        $category = categoryWithQuestions(12);
        $room = Room::factory()->finished()->create(['settings' => ['category_ids' => [$category->id], ...$settings]]);
        RoomPlayer::factory()->for($room)->count($players)->create(['left_at' => null])->each(fn (RoomPlayer $player) => photos()->set($player, upload(jpegBytes(120, 120))));

        return $room;
    }

    it('copies every connected player\'s photo into the new room\'s folder, as a file of its own', function () {
        $old = rematchSetup();

        $new = app(RoomManager::class)->playAgain($old, $old->host);

        $oldRows = $old->players()->orderBy('id')->get();
        $newRows = $new->players()->orderBy('id')->get();
        expect($newRows)->toHaveCount(2)->and(storedPhotos())->toHaveCount(4);
        foreach ($newRows as $i => $row) {
            expect($row->nickname)->toBe($oldRows[$i]->nickname)
                ->and($row->avatar_path)->toStartWith("rooms/{$new->id}/")->and($row->avatar_path)->not->toBe($oldRows[$i]->avatar_path)
                ->and(Storage::disk(PlayerPhoto::DISK)->get($row->avatar_path))->toBe(Storage::disk(PlayerPhoto::DISK)->get($oldRows[$i]->avatar_path));
        }
    });

    it('does not share a file between the rooms: deleting one room never breaks the other', function () {
        $old = rematchSetup();
        $new = app(RoomManager::class)->playAgain($old, $old->host);
        $copied = $new->players()->pluck('avatar_path');

        $old->delete();

        expect($copied->every(fn (string $path) => Storage::disk(PlayerPhoto::DISK)->exists($path)))->toBeTrue()->and(storedPhotos())->toHaveCount(2);
    });

    it('leaves players who had no photo without one, and does not copy a disconnected player', function () {
        $old = rematchSetup(players: 1);
        RoomPlayer::factory()->for($old)->create(['left_at' => null, 'nickname' => 'Nophoto']);
        RoomPlayer::factory()->for($old)->create(['left_at' => now()->subMinute(), 'nickname' => 'Gone']);

        $new = app(RoomManager::class)->playAgain($old, $old->host);

        expect($new->players()->where('nickname', 'Nophoto')->sole()->avatar_path)->toBeNull()->and($new->players()->where('nickname', 'Gone')->exists())->toBeFalse()
            ->and($new->players()->whereNotNull('avatar_path')->count())->toBe(1);
    });

    it('copies nothing when the room has photos off', function () {
        $old = rematchSetup(['show_photos' => false], players: 0);
        $player = RoomPlayer::factory()->for($old)->create(['left_at' => null]);
        $player->forceFill(['avatar_path' => 'rooms/'.$old->id.'/stale.webp'])->save();
        Storage::disk(PlayerPhoto::DISK)->put("rooms/{$old->id}/stale.webp", 'x');

        $new = app(RoomManager::class)->playAgain($old, $old->host);

        expect($new->players()->sole()->avatar_path)->toBeNull();
    });

    it('lets a player pick a new photo in the new lobby without touching the old room\'s', function () {
        $old = rematchSetup(players: 1);
        $new = app(RoomManager::class)->playAgain($old, $old->host);
        $oldPath = $old->players()->sole()->avatar_path;
        $copy = $new->players()->sole();

        app(RoomRoster::class)->setPhoto($copy, upload(jpegBytes(90, 90)));

        expect($copy->fresh()->avatar_path)->not->toBe($oldPath)->and(Storage::disk(PlayerPhoto::DISK)->exists($oldPath))->toBeTrue()->and(storedPhotos())->toHaveCount(2);
    });
});

describe('a player who leaves, is removed or is dropped', function () {
    it('takes their photo with them', function (string $how) {
        $room = Room::factory()->create();
        $player = RoomPlayer::factory()->for($room)->create(['left_at' => $how === 'dropped' ? now()->subMinute() : null]);
        $stays = RoomPlayer::factory()->for($room)->create(['left_at' => null]);
        photos()->set($player, upload(jpegBytes(80, 80)));
        photos()->set($stays, upload(jpegBytes(80, 80)));
        $gone = $player->fresh()->avatar_path;

        match ($how) {
            'leaves' => app(RoomRoster::class)->leave($player->fresh()),
            'removed by the host' => app(RoomRoster::class)->remove($room, $room->host, $player->id),
            'dropped' => app(RoomRoster::class)->pruneDisconnected($room),
        };

        expect(Storage::disk(PlayerPhoto::DISK)->exists($gone))->toBeFalse()->and(storedPhotos())->toBe([$stays->fresh()->avatar_path]);
    })->with(['leaves', 'removed by the host', 'dropped']);
});

describe('lamma:prune', function () {
    it('deletes the photos of a room that finished more than 24 hours ago, and keeps a recent one', function () {
        $old = roomWithSelfies(['status' => RoomStatus::Finished, 'finished_at' => now()->subHours(24)->subMinute()]);
        $recent = roomWithSelfies(['status' => RoomStatus::Finished, 'finished_at' => now()->subHours(23)]);

        $this->artisan('lamma:prune')->assertSuccessful()->expectsOutputToContain('Deleted 2 expired player photos.');

        expect($old->players()->whereNotNull('avatar_path')->count())->toBe(0)->and($recent->players()->whereNotNull('avatar_path')->count())->toBe(2)
            ->and(Storage::disk(PlayerPhoto::DISK)->allFiles("rooms/{$old->id}"))->toBe([])->and(Storage::disk(PlayerPhoto::DISK)->allFiles("rooms/{$recent->id}"))->toHaveCount(2);
    });

    it('follows lamma.photo_retention_hours', function () {
        config(['lamma.photo_retention_hours' => 2]);
        $room = roomWithSelfies(['status' => RoomStatus::Finished, 'finished_at' => now()->subHours(3)]);
        $kept = roomWithSelfies(['status' => RoomStatus::Finished, 'finished_at' => now()->subHour()]);

        $this->artisan('lamma:prune')->assertSuccessful();

        expect($room->players()->whereNotNull('avatar_path')->count())->toBe(0)->and($kept->players()->whereNotNull('avatar_path')->count())->toBe(2);
    });

    it('keeps the photos of a room the host closed until the retention time is over, so the results screen still has them', function () {
        $room = roomWithSelfies();
        app(RoomManager::class)->close($room);

        expect(storedPhotos())->toHaveCount(2);
        $this->artisan('lamma:prune')->assertSuccessful();
        expect(storedPhotos())->toHaveCount(2);

        $this->travelTo(now()->addHours(25));
        $this->artisan('lamma:prune')->assertSuccessful();
        expect(storedPhotos())->toBe([]);
    });

    it('deletes the photos of an abandoned lobby as soon as it is closed', function () {
        $abandoned = roomWithSelfies(['created_at' => now()->subHours(7)]);
        $fresh = roomWithSelfies(['created_at' => now()->subHours(5)]);

        $this->artisan('lamma:prune')->assertSuccessful();

        expect($abandoned->fresh()->status)->toBe(RoomStatus::Finished)->and($abandoned->players()->whereNotNull('avatar_path')->count())->toBe(0)
            ->and($fresh->players()->whereNotNull('avatar_path')->count())->toBe(2);
    });

    it('deletes the photos of a game nobody ended after a day, and leaves one being played', function () {
        $stuck = roomWithSelfies(['status' => RoomStatus::Playing, 'created_at' => now()->subHours(25)]);
        $playing = roomWithSelfies(['status' => RoomStatus::Playing, 'created_at' => now()->subHours(2)]);

        $this->artisan('lamma:prune')->assertSuccessful();

        expect($stuck->players()->whereNotNull('avatar_path')->count())->toBe(0)->and($playing->players()->whereNotNull('avatar_path')->count())->toBe(2);
    });

    it('sweeps the folder of a room that no longer exists, and nothing else', function () {
        $room = roomWithSelfies(['status' => RoomStatus::Finished, 'finished_at' => now()->subHour()]);
        Storage::disk(PlayerPhoto::DISK)->put('rooms/987654/orphan.webp', 'x');

        expect(app(Housekeeping::class)->prunePhotos())->toBe(1);

        expect(Storage::disk(PlayerPhoto::DISK)->exists('rooms/987654/orphan.webp'))->toBeFalse()->and(storedPhotos())->toHaveCount(2)
            ->and($room->players()->whereNotNull('avatar_path')->count())->toBe(2);
    });

    it('does nothing for rooms without photos and says so', function () {
        Room::factory()->finished()->create(['finished_at' => now()->subDays(3)]);

        $this->artisan('lamma:prune')->assertSuccessful()->expectsOutputToContain('Deleted 0 expired player photos.');
    });
});

describe('deleting an account', function () {
    it('deletes the account\'s photos but keeps the finished game for everyone else', function () {
        $user = User::factory()->create();
        $room = Room::factory()->finished()->create();
        $mine = RoomPlayer::factory()->for($room)->forUser($user)->create();
        $theirs = RoomPlayer::factory()->for($room)->create();
        photos()->set($mine, upload(jpegBytes(80, 80)));
        photos()->set($theirs, upload(jpegBytes(80, 80)));
        $myFile = $mine->fresh()->avatar_path;

        app(DeleteAccount::class)($user);

        expect($mine->fresh()->user_id)->toBeNull()->and($mine->fresh()->avatar_path)->toBeNull()->and(Storage::disk(PlayerPhoto::DISK)->exists($myFile))->toBeFalse()
            ->and($theirs->fresh()->avatar_path)->not->toBeNull()->and(storedPhotos())->toHaveCount(1);
    });
});

describe('what a photo is not', function () {
    it('is never attached to a profile: claiming a guest\'s results leaves the photo with its game and puts nothing on the user', function () {
        $user = User::factory()->create();
        $row = RoomPlayer::factory()->create(['guest_token' => LIFE_TOKEN, 'user_id' => null]);
        photos()->set($row, upload(jpegBytes(80, 80)));
        $path = $row->fresh()->avatar_path;

        $this->withCookie('lamma_guest', LIFE_TOKEN)->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasNoErrors();

        expect($row->fresh()->user_id)->toBe($user->id)->and($row->fresh()->avatar_path)->toBe($path)
            ->and(array_keys($user->fresh()->getAttributes()))->not->toContain('avatar_path')->not->toContain('avatar')->not->toContain('photo')
            ->and(storedPhotos())->toBe([$path]);
    });

    it('never shows in My games', function () {
        Event::fake();
        $user = User::factory()->create();
        [$room, $mine] = savedGame($user, 300);
        photos()->set($mine, upload(jpegBytes(80, 80)));
        photos()->set($room->players()->where('id', '!=', $mine->id)->first(), upload(jpegBytes(80, 80)));

        $html = Livewire::actingAs($user)->test(MyGames::class)->html();

        expect($html)->not->toContain('onerror="this.remove()"')->not->toContain('/storage/')->not->toContain('avatar');
    });
});
