<?php

namespace App\Game;

use App\Models\Room;
use App\Models\RoomPlayer;
use finfo;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * A player's selfie for ONE game, stored on the `player_photos` disk at rooms/{room_id}/{ulid}.webp and pointed to by room_players.avatar_path.
 * Nothing the client sends is trusted: the bytes are checked by content (a real JPEG or WebP, which is what the camera canvas produces), decoded,
 * centre-cropped to a square, resized to 256x256 and re-encoded as WebP, which drops every bit of metadata (GD never copies EXIF, GPS or colour
 * profiles). File names are random and never hold the nickname; there is no listing route. The photo goes with the game: see Housekeeping
 * (retention), RoomPlayer (a deleted player's file) and Room (a deleted room's folder).
 */
class PlayerPhoto
{
    public const DISK = 'player_photos';

    public const MAX_BYTES = 3 * 1024 * 1024;

    public const SIZE = 256;

    public const QUALITY = 80;

    /** The largest picture we will decode: GD keeps about 4 bytes per pixel in memory. The camera canvas never sends more than 640x640. */
    public const MAX_PIXELS = 12_000_000;

    private const TYPES = ['image/jpeg' => IMAGETYPE_JPEG, 'image/webp' => IMAGETYPE_WEBP];

    /**
     * Make this upload the player's photo (replacing, and deleting, the old one). Does not broadcast: the caller says what changed.
     *
     * @throws PhotoException
     */
    public function set(RoomPlayer $player, UploadedFile $file): void
    {
        if (! $player->room->settings->showPhotos) {
            throw PhotoException::off();
        }

        $this->throttle($player);

        if (! $file->isValid() || ($file->getSize() ?: 0) > self::MAX_BYTES) {
            throw PhotoException::tooLarge();
        }

        $webp = $this->process((string) $file->get());
        $disk = $this->disk();
        $path = "rooms/{$player->room_id}/".Str::lower((string) Str::ulid()).'.webp';
        $old = $player->avatar_path;

        if (! $disk->put($path, $webp)) {
            throw PhotoException::unavailable();
        }

        try {
            $player->forceFill(['avatar_path' => $path, 'avatar_updated_at' => now()])->save();
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }

        if ($old !== null) {
            $disk->delete($old);
        }
    }

    /** Decode, crop to a square, resize and re-encode: the bytes of a 256x256 WebP, with no metadata. */
    public function process(string $bytes): string
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw PhotoException::tooLarge();
        }
        if (! function_exists('imagewebp')) {
            throw PhotoException::unavailable();
        }

        $mime = $bytes === '' ? false : (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $info = $bytes === '' ? false : @getimagesizefromstring($bytes);
        if (! is_string($mime) || ! isset(self::TYPES[$mime]) || $info === false || $info[2] !== self::TYPES[$mime]) {
            throw PhotoException::invalid();
        }

        [$width, $height] = $info;
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
            throw PhotoException::tooLarge();
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw PhotoException::invalid();
        }

        $side = min($width, $height);
        $square = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($square, 0, 0, (int) imagecolorallocate($square, 255, 255, 255)); // a transparent WebP is laid on white
        imagecopyresampled($square, $source, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), self::SIZE, self::SIZE, $side, $side);

        ob_start();
        $ok = imagewebp($square, null, self::QUALITY);
        $webp = (string) ob_get_clean();

        return $ok && $webp !== '' ? $webp : throw PhotoException::unavailable();
    }

    /** Take the photo away (the file goes too). Returns whether the player had one. Does not broadcast. */
    public function remove(RoomPlayer $player): bool
    {
        $path = $player->avatar_path;
        if ($path === null) {
            return false;
        }

        $player->forceFill(['avatar_path' => null, 'avatar_updated_at' => null])->save();
        $this->disk()->delete($path);

        return true;
    }

    /** Just the file, for a player whose row is going away. */
    public function forget(RoomPlayer $player): void
    {
        if ($player->avatar_path !== null) {
            $this->disk()->delete($player->avatar_path);
        }
    }

    /** "Play again": the same picture as a file of its own in the new room's folder, so deleting one room never breaks the other. */
    public function copy(RoomPlayer $from, RoomPlayer $to): void
    {
        $disk = $this->disk();
        if ($from->avatar_path === null || ! $disk->exists($from->avatar_path)) {
            return;
        }

        $path = "rooms/{$to->room_id}/".Str::lower((string) Str::ulid()).'.webp';
        if ($disk->copy($from->avatar_path, $path)) {
            $to->forceFill(['avatar_path' => $path, 'avatar_updated_at' => now()])->save();
        }
    }

    /** Every photo of a room goes: the files, the folder and the paths. Returns how many photos the rows had. */
    public function purge(Room $room): int
    {
        $this->disk()->deleteDirectory("rooms/{$room->id}");

        return RoomPlayer::query()->where('room_id', $room->id)->whereNotNull('avatar_path')
            ->update(['avatar_path' => null, 'avatar_updated_at' => null]);
    }

    /** Just a deleted room's folder (its rows are gone with it). */
    public function purgeFolder(int $roomId): void
    {
        $this->disk()->deleteDirectory("rooms/{$roomId}");
    }

    public function url(string $path): string
    {
        return $this->disk()->url($path);
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    /** Per player and per IP (a family on one Wi-Fi shares an address, so that limit is wider). */
    private function throttle(RoomPlayer $player): void
    {
        $limits = [
            'photo:player:'.$player->id => (int) config('lamma.photo_uploads_per_minute'),
            'photo:ip:'.request()->ip() => (int) config('lamma.photo_uploads_per_ip_per_minute'),
        ];

        foreach ($limits as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw PhotoException::tooMany(RateLimiter::availableIn($key));
            }
        }
        foreach (array_keys($limits) as $key) {
            RateLimiter::hit($key, 60);
        }
    }
}
