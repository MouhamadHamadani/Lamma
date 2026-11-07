<?php

use App\Game\QuestionImage;
use App\Game\QuestionImageException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** A JPEG with an EXIF segment (a camera's location and name) in front of the picture. */
function jpegWithExif(): string
{
    $jpeg = jpegBytes(400, 300);
    $exif = "Exif\0\0".'GPS-LATITUDE-33.8938 CameraMaker-9000';

    return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
}

function images(): QuestionImage
{
    return app(QuestionImage::class);
}

describe('a question picture is re-encoded, never kept as sent', function () {
    it('drops the EXIF data: the location and the camera never reach a screen', function () {
        $webp = images()->process(jpegWithExif());

        expect(jpegWithExif())->toContain('GPS-LATITUDE')   // (it was there)
            ->and($webp)->not->toContain('GPS-LATITUDE')->not->toContain('CameraMaker')->not->toContain('EXIF')->not->toContain('Exif');
    });

    it('lays a transparent PNG on white and keeps the proportions', function () {
        $png = imagecreatetruecolor(400, 200);
        imagesavealpha($png, true);
        imagefill($png, 0, 0, (int) imagecolorallocatealpha($png, 0, 0, 0, 127));
        ob_start();
        imagepng($png);

        $webp = images()->process((string) ob_get_clean());

        expect(pixelOf($webp, 10, 10))->toBe([255, 255, 255])->and(getimagesizefromstring($webp)[0])->toBe(400)->and(getimagesizefromstring($webp)[1])->toBe(200);
    });

    it('shrinks a big picture to fit 1200 x 900 without stretching it, and never enlarges a small one', function (int $width, int $height, int $toWidth, int $toHeight) {
        [$w, $h] = getimagesizefromstring(images()->process(jpegBytes($width, $height)));

        expect([$w, $h])->toBe([$toWidth, $toHeight]);
    })->with([
        'wide' => [2400, 1200, 1200, 600],
        'tall' => [900, 1800, 450, 900],
        'already fits' => [800, 600, 800, 600],
        'small' => [300, 200, 300, 200],
        'exactly the limit' => [1200, 900, 1200, 900],
    ]);
});

describe('what is accepted', function () {
    it('takes a real JPEG, PNG or WebP of a sensible size', function (string $kind) {
        $bytes = match ($kind) {
            'jpeg' => jpegBytes(640, 480),
            'png' => pngBytes(640, 480),
            'webp' => webpBytes(640, 480),
        };

        expect(images()->validate($bytes))->toBe([640, 480]);
    })->with(['jpeg', 'png', 'webp']);

    it('refuses by content, not by name: a GIF, text, nothing, a broken picture', function (string $bytes) {
        expect(fn () => images()->validate($bytes))->toThrow(QuestionImageException::class);
    })->with([
        'a GIF' => fn () => gifBytes(),
        'text' => 'a sentence',
        'nothing' => '',
        'a JPEG header with nothing after it' => "\xFF\xD8\xFF\xE0\x00\x10JFIF",
    ]);

    it('refuses a picture that is too small, too wide, too tall or too big a file', function () {
        expect(fn () => images()->validate(jpegBytes(63, 200)))->toThrow(QuestionImageException::class, 'too small')
            ->and(fn () => images()->validate(jpegBytes(200, 63)))->toThrow(QuestionImageException::class, 'too small')
            ->and(fn () => images()->validate(jpegBytes(4097, 100)))->toThrow(QuestionImageException::class, 'too large')
            ->and(fn () => images()->validate(jpegBytes(100, 4097)))->toThrow(QuestionImageException::class, 'too large')
            ->and(fn () => images()->validate(jpegBytes(600, 600).str_repeat('0', QuestionImage::MAX_BYTES)))->toThrow(QuestionImageException::class, '2 MB');
        expect(images()->validate(jpegBytes(64, 64)))->toBe([64, 64]);
    });

    it('limits the pixels as well as each side, so a picture cannot eat the memory', function () {
        expect(QuestionImage::MAX_PIXELS)->toBeLessThanOrEqual(12_000_000)->and(QuestionImage::MAX_SIDE * QuestionImage::MAX_SIDE)->toBeGreaterThan(QuestionImage::MAX_PIXELS);
    });
});

describe('where it goes', function () {
    it('stores a random name ending in .webp on the questions disk and gives a root-relative URL', function () {
        $first = images()->store(jpegBytes(300, 300));
        $second = images()->store(jpegBytes(300, 300));

        expect($first)->toMatch('/^[0-9a-z]{26}\.webp$/')->and($first)->not->toBe($second)
            ->and(Storage::disk(QuestionImage::DISK)->exists($first))->toBeTrue()->and(images()->exists($first))->toBeTrue()
            ->and(images()->url($first))->toStartWith('/')->toEndWith($first);
    });

    it('stores an upload, refuses an invalid one, and forgets a picture on request', function () {
        $path = images()->storeUpload(UploadedFile::fake()->createWithContent('anything.php', jpegBytes(300, 300)));

        expect($path)->toEndWith('.webp');
        images()->delete($path);
        images()->delete(null);
        images()->delete('');

        expect(images()->exists($path))->toBeFalse()->and(images()->exists(null))->toBeFalse()
            ->and(fn () => images()->storeUpload(UploadedFile::fake()->createWithContent('a.jpg', 'not a picture')))->toThrow(QuestionImageException::class);
    });

    it('copies one of our own drawings as it is, under seed/, and nothing else', function () {
        $path = images()->storeShipped('flag-japan.svg');

        expect($path)->toBe('seed/flag-japan.svg')->and(Storage::disk(QuestionImage::DISK)->get($path))->toBe(file_get_contents(database_path('seeders/data/images/flag-japan.svg')))
            ->and(fn () => images()->storeShipped('missing.svg'))->toThrow(QuestionImageException::class)
            ->and(fn () => images()->storeShipped('../../.env'))->toThrow(QuestionImageException::class);   // only a bare file name, from our folder
    });
});
