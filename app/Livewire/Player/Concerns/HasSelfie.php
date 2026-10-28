<?php

namespace App\Livewire\Player\Concerns;

use App\Game\PhotoException;
use App\Game\PlayerIdentity;
use App\Game\PlayerPhoto;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * The server half of the selfie block (<x-lamma.selfie>, resources/js/selfie.js) on the join page and the phone lobby. The browser sends the
 * camera's JPEG into `$photo` (a Livewire upload); the block reads `photoUrl` and `photoError` back. What happens to the upload is the
 * component's business (acceptPhoto): the join page keeps it until Join, the lobby saves it at once. The upload itself is never trusted:
 * App\Game\PlayerPhoto checks its bytes, and a rate limit counts every upload per participant and per IP.
 */
trait HasSelfie
{
    use WithFileUploads;

    /** @var TemporaryUploadedFile|null the selfie the camera just took, waiting to be used */
    public $photo = null;

    /** What the block draws: the saved photo (lobby) or the one waiting for Join (join page). Read by Alpine as $wire.photoUrl. */
    #[Locked]
    public ?string $photoUrl = null;

    /** Why the last photo did not work, in the player's language ("" when all is well). */
    public string $photoError = '';

    /** Does this room let players add a selfie? Without it no upload is accepted and no photo UI is drawn. */
    abstract protected function photosOn(): bool;

    /** Use the upload (the component knows how). Throw a PhotoException to refuse it. */
    abstract protected function acceptPhoto(TemporaryUploadedFile $file): void;

    public function updatedPhoto(): void
    {
        $file = $this->photo;
        $this->photoError = '';
        if (! $file instanceof TemporaryUploadedFile) {
            return;
        }

        try {
            if (! $this->photosOn()) {
                throw PhotoException::off();
            }
            app(PlayerPhoto::class)->throttle(app(PlayerIdentity::class)->participantKey());
            $this->acceptPhoto($file);
            $this->dispatch('photo-status', kind: 'added');
        } catch (PhotoException $e) {
            $this->failPhoto($file, $e->getMessage());
        } catch (Throwable $e) {
            // A photo is an extra: never a broken page. The log gets the reason, never the picture.
            Log::warning('A selfie could not be saved.', ['reason' => $e::class]);
            $this->failPhoto($file, PhotoException::unavailable()->getMessage());
        }
    }

    /** The temporary upload goes as soon as it has been used or refused (it is the raw file, metadata and all). */
    protected function discardUpload(?TemporaryUploadedFile $file = null): void
    {
        $file ??= $this->photo instanceof TemporaryUploadedFile ? $this->photo : null;

        try {
            if ($file !== null) {
                // The upload and the little file that remembers its original name (Livewire writes both).
                FileUploadConfiguration::storage()->delete(FileUploadConfiguration::path($file->getFilename().'.json'));
                $file->delete();
            }
        } catch (Throwable) {
            // already gone
        }
        $this->photo = null;
    }

    private function failPhoto(TemporaryUploadedFile $file, string $message): void
    {
        $this->discardUpload($file);
        $this->photoError = $message;
        $this->dispatch('photo-status', kind: 'failed');
    }
}
