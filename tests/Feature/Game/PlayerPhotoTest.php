<?php

use App\Game\PhotoException;
use App\Game\PlayerPhoto;
use App\Models\Room;
use App\Models\RoomPlayer;
use Illuminate\Support\Facades\Storage;

describe('process', function () {
    it('outputs a 256x256 WebP', function () {
        $out = photos()->process(jpegBytes(640, 640));

        $info = getimagesizefromstring($out);
        expect($info[0])->toBe(256)->and($info[1])->toBe(256)->and($info[2])->toBe(IMAGETYPE_WEBP);
    });

    it('centre-crops a wide or a tall picture to a square', function (int $width, int $height) {
        $out = photos()->process(jpegBytes($width, $height));

        expect(getimagesizefromstring($out)[0])->toBe(256)->and(getimagesizefromstring($out)[1])->toBe(256);
    })->with([[800, 400], [400, 800], [300, 300]]);

    it('crops from the middle: a wide picture keeps its centre, so red on one side and blue on the other', function () {
        $out = photos()->process(jpegBytes(800, 400));

        [$r1, , $b1] = pixelOf($out, 10, 128);
        [$r2, , $b2] = pixelOf($out, 245, 128);

        expect($r1)->toBeGreaterThan(200)->and($b1)->toBeLessThan(60)->and($b2)->toBeGreaterThan(200)->and($r2)->toBeLessThan(60);
    });

    it('accepts a WebP too', function () {
        expect(getimagesizefromstring(photos()->process(webpBytes()))[2])->toBe(IMAGETYPE_WEBP);
    });

    it('strips all metadata: a comment and an EXIF block in the upload are gone from the result', function () {
        $jpeg = jpegBytes();
        $exif = "Exif\0\0SECRET-GPS-LOCATION-51.5N-0.1W";
        $segment = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;
        $comment = "\xFF\xFE".pack('n', 22)."SECRET-COMMENT-TEXT\0";
        $tagged = substr($jpeg, 0, 2).$segment.$comment.substr($jpeg, 2);
        expect($tagged)->toContain('SECRET-GPS-LOCATION');

        $out = photos()->process($tagged);

        expect($out)->not->toContain('SECRET')->not->toContain('Exif')->and(getimagesizefromstring($out))->not->toBeFalse();
    });

    it('rejects what is not a JPEG or a WebP, by content and not by extension', function (string $bytes) {
        expect(fn () => photos()->process($bytes))->toThrow(PhotoException::class);
    })->with([
        'png' => fn () => (function () {
            $i = imagecreatetruecolor(50, 50);
            ob_start();
            imagepng($i);

            return (string) ob_get_clean();
        })(),
        'gif' => fn () => (function () {
            $i = imagecreatetruecolor(50, 50);
            ob_start();
            imagegif($i);

            return (string) ob_get_clean();
        })(),
        'text' => fn () => 'just some text, not a picture',
        'html' => fn () => '<html><script>alert(1)</script></html>',
        'svg' => fn () => '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>',
        'php' => fn () => '<?php echo "pwned";',
        'empty' => fn () => '',
        'cut off jpeg' => fn () => substr(jpegBytes(), 0, 40),
    ]);

    it('rejects a PNG that wears a .jpg name when it is uploaded', function () {
        $player = RoomPlayer::factory()->create();
        $i = imagecreatetruecolor(50, 50);
        ob_start();
        imagepng($i);
        $fakeJpg = upload((string) ob_get_clean(), 'selfie.jpg');

        expect(fn () => photos()->set($player, $fakeJpg))->toThrow(PhotoException::class);
        expect($player->fresh()->avatar_path)->toBeNull()->and(Storage::disk(PlayerPhoto::DISK)->allFiles())->toBe([]);
    });

    it('refuses more than 3 MB', function () {
        expect(fn () => photos()->process(str_repeat('a', PlayerPhoto::MAX_BYTES + 1)))->toThrow(PhotoException::class);
    });

    it('handles huge dimensions: a big camera picture is brought down, an absurd one is refused before it is decoded', function () {
        // 3000x2500 = 7.5 MP, a plain camera frame: decoded, cropped and shrunk.
        $out = photos()->process(jpegBytes(3000, 2500, 40));
        expect(getimagesizefromstring($out)[0])->toBe(256);

        // 5000x5000 = 25 MP would need ~100 MB to decode.
        expect(fn () => photos()->process(jpegBytes(5000, 5000, 5)))->toThrow(PhotoException::class);
    });

    it('lays a transparent WebP on white', function () {
        $image = imagecreatetruecolor(100, 100);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        ob_start();
        imagewebp($image, null, 100);

        [$r, $g, $b] = pixelOf(photos()->process((string) ob_get_clean()), 128, 128);

        expect([$r, $g, $b])->each->toBeGreaterThan(245);
    });
});

describe('set', function () {
    it('stores the photo under rooms/{room}/{ulid}.webp and points the player at it', function () {
        $player = RoomPlayer::factory()->create();

        photos()->set($player, upload(jpegBytes()));

        $player->refresh();
        expect($player->avatar_path)->toMatch('#^rooms/'.$player->room_id.'/[0-9a-z]{26}\.webp$#')
            ->and($player->avatar_updated_at)->not->toBeNull()
            ->and(Storage::disk(PlayerPhoto::DISK)->exists($player->avatar_path))->toBeTrue()
            ->and(getimagesizefromstring(Storage::disk(PlayerPhoto::DISK)->get($player->avatar_path))[0])->toBe(256);
    });

    it('never puts the nickname, the token or the account in the file name', function () {
        $player = RoomPlayer::factory()->create(['nickname' => 'Sara']);

        photos()->set($player, upload(jpegBytes(), 'Sara-selfie.jpg'));

        expect(strtolower($player->fresh()->avatar_path))->not->toContain('sara')->not->toContain(strtolower((string) $player->guest_token));
    });

    it('gives every photo a different, random name', function () {
        $paths = RoomPlayer::factory()->count(5)->create()->map(function (RoomPlayer $player) {
            photos()->set($player, upload(jpegBytes(100, 100)));

            return $player->fresh()->avatar_path;
        });

        expect($paths->unique())->toHaveCount(5);
    });

    it('deletes the old file when the photo is replaced', function () {
        $player = RoomPlayer::factory()->create();
        photos()->set($player, upload(jpegBytes()));
        $first = $player->fresh()->avatar_path;

        photos()->set($player->fresh(), upload(jpegBytes()));

        $second = $player->fresh()->avatar_path;
        expect($second)->not->toBe($first)->and(Storage::disk(PlayerPhoto::DISK)->exists($first))->toBeFalse()->and(Storage::disk(PlayerPhoto::DISK)->exists($second))->toBeTrue();
    });

    it('keeps the old photo when the new one is refused', function () {
        $player = RoomPlayer::factory()->create();
        photos()->set($player, upload(jpegBytes()));
        $first = $player->fresh()->avatar_path;

        expect(fn () => photos()->set($player->fresh(), upload('not a picture')))->toThrow(PhotoException::class);

        expect($player->fresh()->avatar_path)->toBe($first)->and(Storage::disk(PlayerPhoto::DISK)->exists($first))->toBeTrue();
    });

    it('refuses in a room that has photos turned off', function () {
        $room = Room::factory()->create(['settings' => ['show_photos' => false]]);
        $player = RoomPlayer::factory()->for($room)->create();

        expect(fn () => photos()->set($player, upload(jpegBytes())))->toThrow(PhotoException::class);
        expect(Storage::disk(PlayerPhoto::DISK)->allFiles())->toBe([]);
    });

    it('limits uploads per participant and per IP', function () {
        config(['lamma.photo_uploads_per_minute' => 3, 'lamma.photo_uploads_per_ip_per_minute' => 5]);

        foreach (range(1, 3) as $n) {
            photos()->throttle('guest:a');
        }
        expect(fn () => photos()->throttle('guest:a'))->toThrow(PhotoException::class, 'Too many photos');

        // another participant on the same IP still has room, until the IP's own limit (5) is used up
        photos()->throttle('guest:b');
        photos()->throttle('guest:b');
        expect(fn () => photos()->throttle('guest:b'))->toThrow(PhotoException::class);
    });

    it('counts a first visit with no account or device by IP alone', function () {
        config(['lamma.photo_uploads_per_ip_per_minute' => 2]);

        photos()->throttle(null);
        photos()->throttle(null);

        expect(fn () => photos()->throttle(null))->toThrow(PhotoException::class);
    });
});

describe('remove and copy', function () {
    it('removes the photo and its file', function () {
        $player = RoomPlayer::factory()->create();
        photos()->set($player, upload(jpegBytes()));
        $path = $player->fresh()->avatar_path;

        expect(photos()->remove($player->fresh()))->toBeTrue();

        expect($player->fresh()->avatar_path)->toBeNull()->and($player->fresh()->avatar_updated_at)->toBeNull()->and(Storage::disk(PlayerPhoto::DISK)->exists($path))->toBeFalse();
        expect(photos()->remove($player->fresh()))->toBeFalse();
    });

    it('deletes the file when the player is deleted', function () {
        $player = RoomPlayer::factory()->create();
        photos()->set($player, upload(jpegBytes()));
        $path = $player->fresh()->avatar_path;

        $player->fresh()->delete();

        expect(Storage::disk(PlayerPhoto::DISK)->exists($path))->toBeFalse();
    });

    it('deletes every file of a room when the room is deleted', function () {
        $room = Room::factory()->create();
        $players = RoomPlayer::factory()->for($room)->count(2)->create();
        $players->each(fn (RoomPlayer $player) => photos()->set($player, upload(jpegBytes(100, 100))));
        expect(Storage::disk(PlayerPhoto::DISK)->allFiles())->toHaveCount(2);

        $room->delete();

        expect(Storage::disk(PlayerPhoto::DISK)->allFiles())->toBe([]);
    });

    it('copies a photo into the other room as a file of its own', function () {
        $from = RoomPlayer::factory()->create();
        photos()->set($from, upload(jpegBytes()));
        $to = RoomPlayer::factory()->create();

        photos()->copy($from->fresh(), $to);

        $to->refresh();
        expect($to->avatar_path)->not->toBe($from->fresh()->avatar_path)->and($to->avatar_path)->toStartWith("rooms/{$to->room_id}/")
            ->and(Storage::disk(PlayerPhoto::DISK)->get($to->avatar_path))->toBe(Storage::disk(PlayerPhoto::DISK)->get($from->fresh()->avatar_path));
    });

    it('copies nothing from a player without a photo', function () {
        $to = RoomPlayer::factory()->create();

        photos()->copy(RoomPlayer::factory()->create(), $to);

        expect($to->fresh()->avatar_path)->toBeNull();
    });
});

describe('what the screens may know', function () {
    it('has the photo URL in the payload when the room shows photos, and none when it does not', function () {
        $on = RoomPlayer::factory()->create();
        photos()->set($on, upload(jpegBytes(100, 100)));
        $off = RoomPlayer::factory()->for(Room::factory()->create(['settings' => ['show_photos' => false]]))->create();

        expect($on->fresh()->toBroadcast())->toHaveKey('avatar_url')->and($on->fresh()->toBroadcast()['avatar_url'])->toContain('/rooms/')
            ->and($off->toBroadcast())->not->toHaveKey('avatar_url')
            ->and(RoomPlayer::factory()->create()->toBroadcast()['avatar_url'])->toBeNull();
    });

    it('never carries the guest token, the account or the file path', function () {
        $player = RoomPlayer::factory()->create();
        photos()->set($player, upload(jpegBytes(100, 100)));
        $payload = json_encode($player->fresh()->toBroadcast());

        expect($payload)->not->toContain($player->guest_token)->not->toContain('guest_token')->not->toContain('user_id')->not->toContain('email')->not->toContain('avatar_path');
    });
});
