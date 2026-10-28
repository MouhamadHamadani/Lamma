<?php

use App\Game\GameEngine;
use App\Game\PlayerPhoto;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * Selfies on the screens, in a real browser: the host lobby, a question ("N of M answered"), the reveal (tiles and scoreboard) and the podium at
 * 1280x720 and 1920x1080 (none of them may scroll), and the phone lobby and results at 390x844. The photos are real files: the disk is pointed at a
 * folder under public/ so the test web server can serve them, and every image must actually load.
 *
 * Run:  timeout 300 vendor/bin/pest tests/Browser/PhotoScreensTest.php
 */

dataset('host screens', [
    '1280x720' => [1280, 720],
    '1920x1080' => [1920, 1080],
]);

beforeEach(function () {
    Queue::fake();
    File::deleteDirectory(public_path('test-photos'));
    Storage::set(PlayerPhoto::DISK, Storage::build(['driver' => 'local', 'root' => public_path('test-photos'), 'url' => '/test-photos', 'visibility' => 'public']));
});

afterEach(fn () => File::deleteDirectory(public_path('test-photos')));

/** Every player of the room gets a real selfie. */
function everyoneHasASelfie(Room $room): void
{
    $room->players()->orderBy('id')->get()->each(fn (RoomPlayer $player) => photos()->set($player, upload(jpegBytes(200, 200))));
}

/** A host and a started room of connected players, all with photos and names of different shapes. */
function photoRoom(int $players = 8): array
{
    $host = User::factory()->create(['preferred_locale' => 'en']);
    $room = gameRoom(players: $players, questions: 5, settings: ['secondsPerQuestion' => 30], host: $host, category: categoryWithQuestions(20));
    $names = ['Sara', 'علي', 'Maximiliano', 'مريم الزهراء', 'Jo', 'عبد الرحمن', 'Alexandria', 'ليلى'];
    foreach ($room->players()->orderBy('id')->get() as $i => $player) {
        $player->update(['nickname' => $names[$i % count($names)].($i >= count($names) ? $i : ''), 'locale' => $i % 2 ? 'ar' : 'en']);
    }
    everyoneHasASelfie($room);

    return [$host, $room->fresh()];
}

function photoNoScroll($page): void
{
    $page->assertScript('document.documentElement.scrollHeight <= window.innerHeight + 1')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth + 1')
        ->assertScript('document.body.scrollHeight <= window.innerHeight + 1');
}

/** Every selfie on the page loaded, and at least $count of them are there. */
function photosLoaded(int $count): string
{
    return "(() => { const photos = [...document.querySelectorAll('img[onerror]')]; return photos.length >= {$count} && photos.every((img) => img.complete && img.naturalWidth === 256); })()";
}

it('shows the photos in the lobby, with no scroll', function (int $width, int $height) {
    [$host, $room] = photoRoom(10);
    $this->actingAs($host);

    $page = visit("/host/{$room->code}")->resize($width, $height)->wait(1);

    $page->assertSeeIn('[data-test=ready-count]', 'of 10 ready')->assertScript(photosLoaded(10));
    photoNoScroll($page);
    $page->assertNoSmoke()->assertNoAccessibilityIssues();
})->with('host screens');

it('shows the photos of the players who answered on the question screen, with no scroll', function (int $width, int $height) {
    [$host, $room] = photoRoom();
    $this->actingAs($host);
    $question = startGame($room);
    foreach ($room->players()->take(3)->get() as $player) {
        answer($player, $question, true);
    }

    $page = visit("/host/{$room->code}")->resize($width, $height)->wait(1);

    $page->assertSeeIn('[data-test=host-game]', '3 of 8 answered')->assertScript("document.querySelectorAll('[data-test=answered-avatars] img[onerror]').length === 8")->assertScript(photosLoaded(8));
    photoNoScroll($page);
    $page->wait(1)->assertNoAccessibilityIssues();
})->with('host screens');

it('shows the photos on the reveal tiles and in the scoreboard, with no scroll', function (int $width, int $height) {
    [$host, $room] = photoRoom();
    $this->actingAs($host);
    $question = startGame($room);
    foreach ($room->players as $i => $player) {
        answer($player, $question, $i % 3 !== 0);
    }
    app(GameEngine::class)->reveal($room, 1);

    $page = visit("/host/{$room->code}")->resize($width, $height)->wait(1);

    $page->assertScript("document.querySelector('[data-test=correct-count]') !== null")
        ->assertScript("document.querySelectorAll('[data-test=scoreboard] img[onerror]').length >= 5")
        ->assertScript("document.querySelectorAll('[data-option] img[onerror]').length >= 4")
        ->assertScript(photosLoaded(9));
    photoNoScroll($page);
    $page->assertNoSmoke();
})->with('host screens');

it('shows the photos on the podium, the winner\'s the biggest, with no scroll', function (int $width, int $height) {
    $host = User::factory()->create(['preferred_locale' => 'en']);
    $room = finishedGame(['Sara' => 500, 'علي' => 400, 'Maximiliano' => 400, 'مريم' => 300, 'Jo' => 100], host: $host);
    everyoneHasASelfie($room);
    $this->actingAs($host);

    $page = visit("/host/{$room->code}")->resize($width, $height);
    $page->wait(4);                                  // the podium builds up for about three seconds

    $page->assertSeeIn('[data-test=host-results]', 'Play again')->assertScript(photosLoaded(3))
        ->assertScript("document.querySelector('[data-rank=\"1\"] img[onerror]').width > document.querySelector('[data-rank=\"2\"] img[onerror]').width");
    photoNoScroll($page);
    $page->assertNoSmoke()->assertNoAccessibilityIssues();
})->with('host screens');

it('falls back to the initial when a photo cannot be loaded', function () {
    [$host, $room] = photoRoom(3);
    $room->players()->orderBy('id')->first()->forceFill(['avatar_path' => 'rooms/999/gone.webp'])->save();
    $this->actingAs($host);

    $page = visit("/host/{$room->code}")->resize(1280, 720)->wait(2);

    // two photos remain, the third avatar lost its broken image and shows its letter
    $page->assertScript("document.querySelectorAll('[data-test=player-list] img[onerror]').length === 2")
        ->assertScript("[...document.querySelectorAll('[data-test=player-list] li')].every((row) => row.querySelector('[aria-hidden=true]') !== null)")
        ->assertNoSmoke();
});

it('shows the photos on the phone, in the lobby and in the results, with no sideways scroll', function () {
    $user = User::factory()->create(['preferred_locale' => 'en']);
    [, $room] = photoRoom(3);
    $mine = RoomPlayer::factory()->for($room)->forUser($user)->create(['nickname' => 'Layla', 'locale' => 'en', 'left_at' => null]);
    photos()->set($mine, upload(jpegBytes(200, 200)));
    $this->actingAs($user);

    $page = visit("/play/{$room->code}")->resize(390, 844)->wait(2);

    $page->assertScript("document.querySelector('[data-test=avatar-button] img[onerror]').naturalWidth === 256")
        ->assertScript("document.querySelectorAll('[data-test=player-list] img[onerror]').length === 4")
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth + 1')
        ->assertNoSmoke()->assertNoAccessibilityIssues();
});
