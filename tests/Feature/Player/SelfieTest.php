<?php

use App\Enums\RoomStatus;
use App\Events\PlayerJoined;
use App\Events\PlayerUpdated;
use App\Game\PlayerPhoto;
use App\Game\RoomRoster;
use App\Livewire\Player\JoinRoom;
use App\Livewire\Player\PlayerLobby;
use App\Models\Room;
use App\Models\RoomPlayer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

const SELFIE_TOKEN = 's1e2l3f4i5e6s1e2l3f4i5e6s1e2l3f4i5e6s1e2l3f4i5e6s1e2l3f4i5e6s1e2';

function selfieJoin(Room $room, string $nickname = 'Sara'): Testable
{
    return Livewire::test(JoinRoom::class)->set('code', $room->code)->set('nickname', $nickname)->set('language', 'en');
}

/** @return array{0: Room, 1: RoomPlayer, 2: Testable} */
function selfieLobby(array $settings = [], array $me = []): array
{
    $room = Room::factory()->create(['settings' => $settings]);
    $player = RoomPlayer::factory()->for($room)->create(['guest_token' => SELFIE_TOKEN, 'nickname' => 'Sara', 'locale' => 'en', ...$me]);

    return [$room, $player, Livewire::withCookie('lamma_guest', SELFIE_TOKEN)->test(PlayerLobby::class, ['room' => $room])];
}

function photoFiles(): array
{
    return Storage::disk(PlayerPhoto::DISK)->allFiles();
}

describe('the join page', function () {
    it('has the selfie step under the nickname when the room has photos on', function () {
        $room = Room::factory()->create();

        $html = selfieJoin($room)->html();

        expect($html)->toContain('data-test="selfie-step"')->toContain('data-test="selfie"')->toContain('Take a selfie')->toContain('Skip')
            ->toContain('Your photo is shown on the host screen and deleted after the game.')
            ->and(strpos($html, 'data-test="nickname-input"'))->toBeLessThan(strpos($html, 'data-test="selfie-step"'));
    });

    it('shows nothing about photos when the room has them off, and before a room is known', function () {
        $off = Room::factory()->create(['settings' => ['show_photos' => false]]);

        foreach ([selfieJoin($off)->html(), Livewire::test(JoinRoom::class)->html(), Livewire::test(JoinRoom::class)->set('code', 'ZZZZZZ')->html()] as $html) {
            expect($html)->not->toContain('data-test="selfie')->not->toContain('selfie')->not->toContain('Your photo is shown')->not->toContain('Take a selfie');
        }
    });

    it('has no file input, no capture attribute and no accept filter anywhere: no way to pick an existing photo', function () {
        $html = selfieJoin(Room::factory()->create())->html();

        expect($html)->not->toContain('type="file"')->not->toContain('capture=')->not->toContain('accept=')->not->toContain('wire:model="photo"')
            ->toContain('<video')->toContain('playsinline')->toContain('muted')->toContain('autoplay');
    });

    it('opens the camera with a front-facing live preview only, and mirrors just the preview', function () {
        $js = file_get_contents(resource_path('js/selfie.js'));

        expect($js)->toContain('getUserMedia')->toContain("facingMode: 'user'")->toContain('audio: false')->toContain('ideal: 720')
            ->toContain('image/jpeg')->toContain('0.85')->toContain('Math.min(side, 640)')
            ->not->toContain('type="file"')->not->toContain("createElement('input')")->not->toContain('showOpenFilePicker');
        expect(file_get_contents(resource_path('views/components/lamma/selfie.blade.php')))->toContain('-scale-x-100');
    });

    it('stops every camera track when the shutter is pressed, the panel is cancelled, the page is hidden or the component goes away', function () {
        $js = file_get_contents(resource_path('js/selfie.js'));

        expect(substr_count($js, 'this.stopCamera()'))->toBeGreaterThanOrEqual(4)
            ->and($js)->toContain('getTracks().forEach((track) => track.stop())')->toContain('visibilitychange')->toContain('pagehide')->toContain('destroy()');
    });

    it('joins without a photo as before', function () {
        $room = Room::factory()->create();
        Event::fake([PlayerJoined::class]);

        selfieJoin($room)->call('join')->assertHasNoErrors()->assertRedirect(route('play', $room->code));

        expect($room->players()->sole()->avatar_path)->toBeNull()->and(photoFiles())->toBe([]);
        Event::assertDispatched(PlayerJoined::class, fn (PlayerJoined $e) => $e->broadcastWith()['player']['avatar_url'] === null);
    });

    it('joins with a selfie: a 256x256 WebP in the room\'s folder, and the join is announced with its URL', function () {
        $room = Room::factory()->create();
        Event::fake([PlayerJoined::class]);

        selfieJoin($room)->set('photo', upload(jpegBytes(640, 640)))->assertSet('photoError', '')->call('join')->assertHasNoErrors();

        $player = $room->players()->sole();
        expect($player->avatar_path)->toStartWith("rooms/{$room->id}/")->and(photoFiles())->toBe([$player->avatar_path])
            ->and(getimagesizefromstring(Storage::disk(PlayerPhoto::DISK)->get($player->avatar_path))[0])->toBe(256);
        Event::assertDispatched(PlayerJoined::class, fn (PlayerJoined $e) => str_contains($e->broadcastWith()['player']['avatar_url'], $player->avatar_path));
    });

    it('shows the photo that is waiting for Join, and lets the player drop it', function () {
        $page = selfieJoin(Room::factory()->create())->set('photo', upload(jpegBytes()));

        expect($page->get('photoUrl'))->toBeString()->not->toBeEmpty();

        $page->call('removePhoto')->assertSet('photo', null)->assertSet('photoUrl', null)->assertDispatched('photo-status', kind: 'removed');
    });

    it('announces "Photo added" to screen readers through the status event', function () {
        selfieJoin(Room::factory()->create())->set('photo', upload(jpegBytes()))->assertDispatched('photo-status', kind: 'added');
    });

    it('refuses a bad picture with a message and a retry, and still lets the player join without it', function (string $bytes) {
        $room = Room::factory()->create();

        $page = selfieJoin($room)->set('photo', upload($bytes));

        expect($page->get('photoError'))->not->toBeEmpty();
        $page->assertSet('photo', null)->assertDispatched('photo-status', kind: 'failed')->call('join')->assertHasNoErrors();
        expect($room->players()->count())->toBe(1)->and(photoFiles())->toBe([]);
    })->with([
        'text' => fn () => 'not a picture at all',
        'png named .jpg' => fn () => (function () {
            $i = imagecreatetruecolor(40, 40);
            ob_start();
            imagepng($i);

            return (string) ob_get_clean();
        })(),
    ]);

    it('still joins when the photo cannot be saved, and logs the reason, never the picture', function () {
        $room = Room::factory()->create();
        Log::spy();
        $page = selfieJoin($room)->set('photo', upload(jpegBytes()));
        $this->app->bind(PlayerPhoto::class, fn () => new class extends PlayerPhoto
        {
            public function set(RoomPlayer $player, UploadedFile $file): void
            {
                throw new RuntimeException('the disk is full');
            }
        });

        $page->call('join')->assertHasNoErrors();

        expect($room->players()->count())->toBe(1);
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $message === 'A selfie was left out of a join.' && $context === ['room' => $room->id, 'reason' => RuntimeException::class]);
    });

    it('leaves a photo out of a room that has photos off, even if the browser sent one', function () {
        $room = Room::factory()->create(['settings' => ['show_photos' => false]]);

        selfieJoin($room)->set('photo', upload(jpegBytes()))->call('join')->assertHasNoErrors();

        expect($room->players()->sole()->avatar_path)->toBeNull()->and(photoFiles())->toBe([]);
    });

    it('does not keep the raw upload around after joining', function () {
        $room = Room::factory()->create();

        selfieJoin($room)->set('photo', upload(jpegBytes()))->call('join');

        expect(Storage::disk('tmp-for-tests')->allFiles())->toBe([]);
    });

    it('limits uploads: past the limit a minute is refused with a message (a first visit is counted by IP)', function () {
        config(['lamma.photo_uploads_per_ip_per_minute' => 6]);
        $room = Room::factory()->create();
        $page = selfieJoin($room);

        foreach (range(1, 6) as $n) {
            $page->set('photo', upload(jpegBytes(64, 64)))->assertSet('photoError', '');
        }
        $page->set('photo', upload(jpegBytes(64, 64)));

        expect($page->get('photoError'))->toContain('Too many photos');
    });

    it('remembers the last nickname in the browser as a suggestion, never the photo', function () {
        $html = selfieJoin(Room::factory()->create())->html();

        expect($html)->toContain('lammaRememberNickname')->and(file_get_contents(resource_path('js/selfie.js')))
            ->toContain("localStorage.getItem('lamma_nickname')")->toContain("localStorage.setItem('lamma_nickname'")->not->toContain('lamma_photo');
    });

    it('is in Arabic, right-to-left, with the nickname field still left-to-right', function () {
        $room = Room::factory()->create();

        $html = $this->withSession(['locale' => 'ar'])->get(route('join', ['code' => $room->code]))->getContent();

        expect($html)->toContain('dir="rtl"')->toContain('التقط صورة شخصية')->toContain('تخطَّ')->toContain('تظهر صورتك على شاشة المضيف وتُحذف بعد انتهاء اللعبة.')
            ->toMatch('/id="join-nickname"[^>]*dir="ltr"/');
    });
});

describe('the phone lobby', function () {
    it('has the avatar as a button that opens the profile panel, collapsed at first', function () {
        [, , $page] = selfieLobby();

        $page->assertSeeHtml('data-test="avatar-button"')->assertSeeHtml('aria-expanded="false"')->assertDontSeeHtml('data-test="profile-panel"')->assertDontSeeHtml('data-test="selfie"');

        $page->call('toggleProfile')->assertSeeHtml('aria-expanded="true"')->assertSeeHtml('data-test="profile-panel"')->assertSeeHtml('data-test="selfie"')
            ->assertSee('Your photo is shown on the host screen and deleted after the game.')->assertDontSeeHtml('type="file"');

        $page->call('toggleProfile')->assertDontSeeHtml('data-test="profile-panel"');
    });

    it('has no photo UI at all when the room has photos off, but the name can still be changed', function () {
        [, , $page] = selfieLobby(['show_photos' => false]);

        $page->call('toggleProfile')->assertSeeHtml('data-test="profile-panel"')->assertSeeHtml('data-test="profile-nickname"')
            ->assertDontSeeHtml('data-test="selfie"')->assertDontSee('Your photo is shown')->assertDontSee('Take a selfie');
    });

    it('saves a new photo at once, deletes the old file and tells the others with the new URL', function () {
        Event::fake([PlayerUpdated::class]);
        [, $player, $page] = selfieLobby();

        $page->call('toggleProfile')->set('photo', upload(jpegBytes()))->assertSet('photoError', '')->assertSet('photo', null);
        $first = $player->fresh()->avatar_path;
        expect($first)->not->toBeNull()->and(photoFiles())->toBe([$first]);
        $page->assertSeeHtml('/'.$first);

        $page->set('photo', upload(jpegBytes(300, 300)));
        $second = $player->fresh()->avatar_path;
        expect($second)->not->toBe($first)->and(photoFiles())->toBe([$second]);

        Event::assertDispatched(PlayerUpdated::class, fn (PlayerUpdated $e) => str_contains($e->broadcastWith()['player']['avatar_url'] ?? '', $second));
        expect(Storage::disk('tmp-for-tests')->allFiles())->toBe([]);
    });

    it('removes the player\'s own photo, with a broadcast', function () {
        Event::fake([PlayerUpdated::class]);
        [, $player, $page] = selfieLobby();
        $page->set('photo', upload(jpegBytes()));

        $page->call('removePhoto')->assertDispatched('photo-status', kind: 'removed');

        expect($player->fresh()->avatar_path)->toBeNull()->and(photoFiles())->toBe([]);
        Event::assertDispatched(PlayerUpdated::class, fn (PlayerUpdated $e) => $e->broadcastWith()['player']['avatar_url'] === null);
    });

    it('refuses a bad picture and keeps the old photo', function () {
        [, $player, $page] = selfieLobby();
        $page->set('photo', upload(jpegBytes()));
        $kept = $player->fresh()->avatar_path;

        $page->set('photo', upload('not a picture'));

        expect($page->get('photoError'))->not->toBeEmpty()->and($player->fresh()->avatar_path)->toBe($kept);
    });

    it('does not accept a photo in a room with photos off', function () {
        [$room, $player, $page] = selfieLobby(['show_photos' => false]);

        $page->set('photo', upload(jpegBytes()));

        expect($player->fresh()->avatar_path)->toBeNull()->and(photoFiles())->toBe([])->and($page->get('photoError'))->not->toBeEmpty();
    });

    it('does not change anything once the game has started', function () {
        [$room, $player, $page] = selfieLobby();
        $room->update(['status' => RoomStatus::Playing]);

        $page->set('photo', upload(jpegBytes()));

        expect($player->fresh()->avatar_path)->toBeNull()->and($page->get('photoError'))->not->toBeEmpty();
    });

    it('changes the nickname and tells the others, with no token or email in the payload', function () {
        Event::fake([PlayerUpdated::class]);
        [, $player, $page] = selfieLobby();

        $page->call('toggleProfile')->set('nickname', '  Laila  ')->call('saveName')->assertHasNoErrors()->assertSet('nameError', '');

        expect($player->fresh()->nickname)->toBe('Laila');
        Event::assertDispatched(PlayerUpdated::class, function (PlayerUpdated $e) use ($player) {
            $payload = $e->broadcastWith();

            return $payload['player']['id'] === $player->id && $payload['player']['nickname'] === 'Laila'
                && array_key_exists('avatar_url', $payload['player'])
                && ! str_contains(json_encode($payload), SELFIE_TOKEN) && ! str_contains(json_encode($payload), 'guest_token') && ! str_contains(json_encode($payload), 'email');
        });
    });

    it('keeps nicknames unique in the room, ignoring case, but lets a player keep or re-case their own', function () {
        [$room, $player, $page] = selfieLobby();
        RoomPlayer::factory()->for($room)->create(['nickname' => 'Ben']);

        $page->call('toggleProfile')->set('nickname', 'ben')->call('saveName');
        expect($page->get('nameError'))->toBe('That nickname is taken in this room')->and($player->fresh()->nickname)->toBe('Sara');

        $page->set('nickname', 'SARA')->call('saveName')->assertSet('nameError', '');
        expect($player->fresh()->nickname)->toBe('SARA');
    });

    it('applies the nickname rules: 2 to 20 characters', function (string $nickname) {
        Event::fake([PlayerUpdated::class]);
        [, $player, $page] = selfieLobby();

        $page->call('toggleProfile')->set('nickname', $nickname)->call('saveName')->assertHasErrors('nickname');

        expect($player->fresh()->nickname)->toBe('Sara');
        Event::assertNotDispatched(PlayerUpdated::class);
    })->with(['', 'A', str_repeat('x', 21)]);

    it('shows the host\'s removal as a short message, once, and lets the player pick another photo', function () {
        [$room, $player, $page] = selfieLobby();
        $page->set('photo', upload(jpegBytes()));

        app(RoomRoster::class)->removePhotoAsHost($room, $room->host, $player->id);

        $page->call('$refresh')->assertSee('The host removed your photo')->assertSeeHtml('data-test="photo-notice"');
        $page->call('$refresh')->assertSee('The host removed your photo');   // it stays until a new photo is chosen
        $page->call('toggleProfile')->set('photo', upload(jpegBytes()))->assertDontSee('The host removed your photo');
        expect($player->fresh()->avatar_path)->not->toBeNull();
    });

    it('refreshes when someone changes their name or photo (PlayerUpdated)', function () {
        [$room, , $page] = selfieLobby();

        expect($page->instance()->getListeners())->toHaveKey("echo-presence:room.{$room->code},PlayerUpdated", '$refresh');
    });

    it('opens first-time rematch lobbies on a Keep / Change choice, once', function () {
        $old = Room::factory()->finished()->create();
        $room = Room::factory()->create();
        $old->update(['next_room_id' => $room->id]);
        RoomPlayer::factory()->for($room)->create(['guest_token' => SELFIE_TOKEN, 'nickname' => 'Sara', 'locale' => 'en']);

        $first = Livewire::withCookie('lamma_guest', SELFIE_TOKEN)->test(PlayerLobby::class, ['room' => $room]);
        $first->assertSet('choosing', true)->assertSeeHtml('data-test="profile-keep"')->assertSeeHtml('data-test="profile-change"')->assertSee('Keep your name and photo for this round, or pick new ones.');

        $first->call('changeProfile')->assertSet('choosing', false)->assertSet('editing', true)->assertSeeHtml('data-test="profile-nickname"')->assertSeeHtml('data-test="selfie"');

        // a reload within the same browser session no longer interrupts
        $again = Livewire::withCookie('lamma_guest', SELFIE_TOKEN)->test(PlayerLobby::class, ['room' => $room]);
        $again->assertSet('choosing', false)->assertSet('editing', false);
    });

    it('lets "Keep" close the choice, and Ready counts as keeping', function () {
        $old = Room::factory()->finished()->create();
        $room = Room::factory()->create();
        $old->update(['next_room_id' => $room->id]);
        RoomPlayer::factory()->for($room)->create(['guest_token' => SELFIE_TOKEN, 'nickname' => 'Sara', 'locale' => 'en']);

        $page = Livewire::withCookie('lamma_guest', SELFIE_TOKEN)->test(PlayerLobby::class, ['room' => $room]);
        $page->call('keepProfile')->assertSet('choosing', false)->assertSet('editing', false)->assertDontSeeHtml('data-test="profile-panel"');

        $other = Livewire::withCookie('lamma_guest', SELFIE_TOKEN)->test(PlayerLobby::class, ['room' => $room]);
        $other->assertSet('choosing', false);
    });

    it('does not offer Keep / Change in a first game', function () {
        [, , $page] = selfieLobby();

        $page->assertSet('choosing', false)->assertDontSeeHtml('data-test="profile-keep"');
    });
});
