<?php

namespace App\Game;

use finfo;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The picture of a picture question, stored on the `question_images` disk (storage/app/public/questions, served at /storage/questions with long cache
 * headers: the names are random, so a replaced picture is a new URL). Nothing the client sends is trusted, exactly as for a player's selfie
 * (PlayerPhoto): the bytes are checked by content (a real JPEG, PNG or WebP; not the name, not the type the client claims; no SVG, no GIF), limited in size
 * and in dimensions, decoded with GD and re-encoded as WebP, which drops every bit of metadata and anything hidden after the image data. The picture is
 * scaled down to fit 1200 x 900 (never up) and keeps its proportions. Only the admin uploads these, so the messages are plain English.
 */
class QuestionImage
{
    public const DISK = 'question_images';

    public const MAX_BYTES = 2 * 1024 * 1024;

    /** The widest or tallest picture we will look at, and (because GD keeps about 4 bytes a pixel in memory) the most pixels we will decode. */
    public const MAX_SIDE = 4096;

    public const MAX_PIXELS = 12_000_000;

    public const MIN_SIDE = 64;

    public const FIT_WIDTH = 1200;

    public const FIT_HEIGHT = 900;

    public const QUALITY = 82;

    private const TYPES = ['image/jpeg' => IMAGETYPE_JPEG, 'image/png' => IMAGETYPE_PNG, 'image/webp' => IMAGETYPE_WEBP];

    /**
     * Is this a picture we would accept? Checks the size and what the bytes really are, and how large the picture is, without decoding it.
     *
     * @return array{0: int, 1: int} width and height
     *
     * @throws QuestionImageException
     */
    public function validate(string $bytes): array
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw QuestionImageException::tooBig();
        }

        $mime = $bytes === '' ? false : (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $info = $bytes === '' ? false : @getimagesizefromstring($bytes);
        if (! is_string($mime) || ! isset(self::TYPES[$mime]) || $info === false || $info[2] !== self::TYPES[$mime]) {
            throw QuestionImageException::invalid();
        }

        [$width, $height] = $info;
        if ($width < self::MIN_SIDE || $height < self::MIN_SIDE) {
            throw QuestionImageException::tooSmall();
        }
        if ($width > self::MAX_SIDE || $height > self::MAX_SIDE || $width * $height > self::MAX_PIXELS) {
            throw QuestionImageException::tooLarge();
        }

        return [$width, $height];
    }

    /** Decode, fit inside 1200 x 900 and re-encode: the bytes of a WebP with no metadata. A transparent picture is laid on white. */
    public function process(string $bytes): string
    {
        if (! function_exists('imagewebp')) {
            throw QuestionImageException::unavailable();
        }

        [$width, $height] = $this->validate($bytes);

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw QuestionImageException::invalid();
        }

        $scale = min(1, self::FIT_WIDTH / $width, self::FIT_HEIGHT / $height);
        $toWidth = max(1, (int) round($width * $scale));
        $toHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($toWidth, $toHeight);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $toWidth, $toHeight, $width, $height);

        ob_start();
        $ok = imagewebp($canvas, null, self::QUALITY);
        $webp = (string) ob_get_clean();

        return $ok && $webp !== '' ? $webp : throw QuestionImageException::unavailable();
    }

    /**
     * Process these bytes and store them under a random name. Returns the path to keep in `questions.image_path` (relative to the disk).
     *
     * @throws QuestionImageException
     */
    public function store(string $bytes): string
    {
        $webp = $this->process($bytes);
        $path = Str::lower((string) Str::ulid()).'.webp';

        return $this->disk()->put($path, $webp) ? $path : throw QuestionImageException::unavailable();
    }

    /** @throws QuestionImageException */
    public function storeUpload(UploadedFile $file): string
    {
        if (! $file->isValid()) {
            throw QuestionImageException::invalid();
        }

        return $this->store((string) $file->get());
    }

    /**
     * Copy one of our own drawings (database/seeders/data/images: SVG art we ship) onto the disk under seed/, unchanged. Only the seeder calls this, with files that
     * are in the repository: an upload never gets here. Returns the path for `questions.image_path`.
     */
    public function storeShipped(string $file): string
    {
        $source = database_path('seeders/data/images/'.basename($file));
        $path = 'seed/'.basename($file);

        if (! is_file($source) || ! $this->disk()->put($path, (string) file_get_contents($source))) {
            throw QuestionImageException::unavailable();
        }

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '') {
            $this->disk()->delete($path);
        }
    }

    public function exists(?string $path): bool
    {
        return $path !== null && $path !== '' && $this->disk()->exists($path);
    }

    /** A root-relative URL (like a selfie's), so a phone on the laptop's LAN address loads it from the same host as the page. */
    public function url(string $path): string
    {
        return $this->disk()->url($path);
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
