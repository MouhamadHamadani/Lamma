<?php

use App\Events\PlayerUpdated;
use App\Filament\Resources\Rooms\Pages\ViewRoom;
use App\Filament\Resources\Rooms\RelationManagers\PlayersRelationManager;
use App\Game\PlayerPhoto;
use App\Game\RoomRoster;
use App\Livewire\Host\HostLobby;
use App\Livewire\Player\PlayerLobby;
use App\Models\Admin;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

const MOD_TOKEN = 'm0d0000000000000000000000000000000000000000000000000000000000000';

beforeEach(fn () => Event::fake([PlayerUpdated::class]));

/** A lobby with two players who have selfies; the first is the phone under test. */
function moderatedRoom(array $settings = []): array
{
    $room = Room::factory()->create(['settings' => $settings]);
    $a = RoomPlayer::factory()->for($room)->create(['guest_token' => MOD_TOKEN, 'nickname' => 'Sara', 'locale' => 'en', 'left_at' => null]);
    $b = RoomPlayer::factory()->for($room)->create(['nickname' => 'Ben', 'left_at' => null]);
    photos()->set($a, upload(jpegBytes(100, 100)));
    photos()->set($b, upload(jpegBytes(100, 100)));

    return [$room, $a->fresh(), $b->fresh()];
}

describe('the host', function () {
    it('has a Remove photo action on a row with a photo, next to Remove player, and none on a row without', function () {
        [$room, , $b] = moderatedRoom();
        $b->forceFill(['avatar_path' => null])->save();

        $html = Livewire::actingAs($room->host)->test(HostLobby::class, ['room' => $room])->html();

        expect(substr_count($html, 'data-test="remove-photo"'))->toBe(1)->and(substr_count($html, 'data-test="remove-player"'))->toBe(2)
            ->and($html)->toContain(e("Remove Sara's photo"))->not->toContain(e("Remove Ben's photo"))
            ->and(strpos($html, 'data-test="remove-photo"'))->toBeLessThan(strpos($html, 'data-test="remove-player"'));
    });

    it('takes a photo down: the file is deleted, the player keeps playing with their initial, and everybody is told', function () {
        [$room, $a, $b] = moderatedRoom();
        $gone = $a->avatar_path;

        Livewire::actingAs($room->host)->test(HostLobby::class, ['room' => $room])->call('removePhoto', $a->id);

        expect($a->fresh()->avatar_path)->toBeNull()->and($a->fresh()->nickname)->toBe('Sara')->and(Storage::disk(PlayerPhoto::DISK)->exists($gone))->toBeFalse()
            ->and($b->fresh()->avatar_path)->not->toBeNull();
        Event::assertDispatched(PlayerUpdated::class, fn (PlayerUpdated $e) => $e->broadcastWith()['player']['id'] === $a->id && $e->broadcastWith()['player']['avatar_url'] === null);
    });

    it('is the only one who can: another account is refused, and a player of another room is out of reach', function () {
        [$room, $a] = moderatedRoom();
        $other = moderatedRoom();

        Livewire::actingAs(User::factory()->create())->test(HostLobby::class, ['room' => $room])->call('removePhoto', $a->id)->assertForbidden();
        expect($a->fresh()->avatar_path)->not->toBeNull();
        expect(fn () => app(RoomRoster::class)->removePhotoAsHost($room, User::factory()->create(), $a->id))->toThrow(AuthorizationException::class);

        Livewire::actingAs($room->host)->test(HostLobby::class, ['room' => $room])->call('removePhoto', $other[1]->id);
        expect($other[1]->fresh()->avatar_path)->not->toBeNull()->and($a->fresh()->avatar_path)->not->toBeNull();
    });

    it('tells the phone: it falls back to the initial and shows "The host removed your photo", and it may pick another one', function () {
        [$room, $a] = moderatedRoom();
        $phone = Livewire::withCookie('lamma_guest', MOD_TOKEN)->test(PlayerLobby::class, ['room' => $room]);
        $phone->assertDontSee('The host removed your photo');

        app(RoomRoster::class)->removePhotoAsHost($room, $room->host, $a->id);

        $phone->call('$refresh')->assertSee('The host removed your photo')->assertSet('photoUrl', null);
        $phone->call('toggleProfile')->set('photo', upload(jpegBytes(90, 90)))->assertSet('photoError', '')->assertDontSee('The host removed your photo');
        expect($a->fresh()->avatar_path)->not->toBeNull();
    });
});

describe('the admin panel', function () {
    beforeEach(function () {
        Filament::setCurrentPanel('admin');
        $this->actingAs(Admin::factory()->create(), 'admin');
    });

    function playersTable(Room $room)
    {
        return Livewire::test(PlayersRelationManager::class, ['ownerRecord' => $room, 'pageClass' => ViewRoom::class]);
    }

    it('lists a room\'s players with their photo thumbnail and a Remove photo action', function () {
        [$room, $a, $b] = moderatedRoom();
        $b->forceFill(['avatar_path' => null])->save();

        playersTable($room)->assertSuccessful()->assertCanSeeTableRecords([$a, $b])->assertTableColumnExists('avatar_path')
            ->assertTableActionVisible('removePhoto', $a)->assertTableActionHidden('removePhoto', $b);
    });

    it('takes a photo down: the file goes and the phone is told', function () {
        [$room, $a] = moderatedRoom();
        $gone = $a->avatar_path;

        playersTable($room)->callTableAction('removePhoto', $a)->assertHasNoTableActionErrors();

        expect($a->fresh()->avatar_path)->toBeNull()->and(Storage::disk(PlayerPhoto::DISK)->exists($gone))->toBeFalse();
        Event::assertDispatched(PlayerUpdated::class);
        $phone = Livewire::withCookie('lamma_guest', MOD_TOKEN)->test(PlayerLobby::class, ['room' => $room]);
        $phone->assertSee('The host removed your photo');
    });

    it('shows on the room page whether photos are on', function () {
        $room = Room::factory()->create(['settings' => ['show_photos' => false]]);

        $this->get('/admin/rooms/'.$room->id)->assertOk()->assertSee('Player photos')->assertSee('Off');
    });
});

describe('privacy: nothing about a photo reaches a log', function () {
    it('logs only the reason when a photo cannot be saved, never the picture or a message that holds it', function () {
        [$room] = moderatedRoom();
        Log::spy();
        $picture = jpegBytes(40, 40);
        $this->app->bind(PlayerPhoto::class, fn () => new class($picture) extends PlayerPhoto
        {
            public function __construct(private readonly string $picture) {}

            public function set(RoomPlayer $player, UploadedFile $file): void
            {
                throw new RuntimeException('could not write '.$this->picture);
            }
        });

        $phone = Livewire::withCookie('lamma_guest', MOD_TOKEN)->test(PlayerLobby::class, ['room' => $room]);
        $phone->call('toggleProfile')->set('photo', upload($picture));

        expect($phone->get('photoError'))->not->toBeEmpty()->not->toContain($picture);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === 'A selfie could not be saved.' && $context === ['reason' => RuntimeException::class] && ! str_contains($message.json_encode($context), 'JFIF'));
    });

    it('logs nothing at all for a picture that is simply refused', function () {
        [$room] = moderatedRoom();
        Log::spy();

        $phone = Livewire::withCookie('lamma_guest', MOD_TOKEN)->test(PlayerLobby::class, ['room' => $room]);
        $phone->call('toggleProfile')->set('photo', upload('not a picture'));

        expect($phone->get('photoError'))->not->toBeEmpty();
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('info');
    });

    it('limits the uploads of one participant to six a minute, even from different addresses', function () {
        [$room] = moderatedRoom();
        $phone = Livewire::withCookie('lamma_guest', MOD_TOKEN)->test(PlayerLobby::class, ['room' => $room]);
        $phone->call('toggleProfile');

        foreach (range(1, 6) as $n) {
            $phone->set('photo', upload(jpegBytes(64, 64)))->assertSet('photoError', '');
        }

        $phone->set('photo', upload(jpegBytes(64, 64)));
        expect($phone->get('photoError'))->toContain('Too many photos');
    });
});
