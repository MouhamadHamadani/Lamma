<?php

namespace App\Livewire\Player;

use App\Game\JoinException;
use App\Game\PlayerIdentity;
use App\Game\PlayerPhoto;
use App\Game\RoomCodeGenerator;
use App\Game\RoomJoiner;
use App\Livewire\Player\Concerns\HasSelfie;
use App\Models\Room;
use App\Support\Analytics;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/** A phone enters a room code and a nickname. Who may join, and under which name, is decided by App\Game\RoomJoiner. */
#[Layout('layouts::lamma')]
class JoinRoom extends Component
{
    use HasSelfie;

    public const NICKNAME_MIN = 2;

    public const NICKNAME_MAX = 20;

    public string $code = '';

    public string $nickname = '';

    /** ar | en: the language this player plays in. */
    public string $language = 'en';

    public function mount(Request $request, PlayerIdentity $identity): void
    {
        $this->code = RoomCodeGenerator::normalize((string) $request->query('code', ''));
        $user = $identity->user();
        $this->nickname = $user ? Str::limit(Str::squish($user->name), self::NICKNAME_MAX, '') : '';
        $this->language = app()->getLocale() === 'ar' ? 'ar' : 'en';

        // Opening the join link again from a device that is already in the room goes straight back to it.
        $room = $this->code === '' ? null : Room::where('code', $this->code)->first();
        if ($room && $identity->playerIn($room)) {
            $this->redirectRoute('play', ['room' => $room->code]);
        }
    }

    public function join(RoomJoiner $joiner): mixed
    {
        $this->resetErrorBag(); // errors added by hand last time (not found, nickname taken) would otherwise pile up

        $attempts = 'join:'.request()->ip();
        if (RateLimiter::tooManyAttempts($attempts, (int) config('lamma.join_attempts_per_minute'))) {
            $this->addError('code', __('Too many attempts. Please try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($attempts)]));

            return null;
        }
        RateLimiter::hit($attempts, 60);

        $this->code = RoomCodeGenerator::normalize($this->code);
        $this->nickname = Str::squish($this->nickname);
        $this->validate();

        // The selfie is an extra: only when this room has photos on, and whatever happens to it the player still joins (RoomJoiner).
        $photo = $this->photo instanceof TemporaryUploadedFile && $this->photosOn() ? $this->photo : null;

        try {
            $joiner->join($this->code, $this->nickname, $this->language, $photo);
        } catch (JoinException $e) {
            $this->addError($e->field, $e->getMessage());

            return null;
        }

        $this->discardUpload();
        Analytics::flash('player_joined', ['language' => $this->language]);

        return $this->redirectRoute('play', ['room' => $this->code]);
    }

    /** The selfie step shows only for a room with photos on (the code in the field decides, once it is complete). */
    protected function photosOn(): bool
    {
        $code = RoomCodeGenerator::normalize($this->code);

        return strlen($code) === RoomCodeGenerator::LENGTH && Room::where('code', $code)->first()?->settings->showPhotos === true;
    }

    /** Nothing is saved before Join: here the picture is only checked, so a bad one is refused straight away, with a way to retry. */
    protected function acceptPhoto(TemporaryUploadedFile $file): void
    {
        $bytes = $file->get();

        app(PlayerPhoto::class)->validate($bytes === false ? '' : $bytes);
    }

    /** The photo is dropped from the page (it was never saved). */
    public function removePhoto(): void
    {
        $this->discardUpload();
        $this->photoError = '';
        $this->dispatch('photo-status', kind: 'removed');
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'code' => ['required', 'size:'.RoomCodeGenerator::LENGTH],
            'nickname' => ['required', 'string', 'min:'.self::NICKNAME_MIN, 'max:'.self::NICKNAME_MAX],
            'language' => ['in:ar,en'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'code.required' => __('Enter the 6-character room code.'),
            'code.size' => __('Enter the 6-character room code.'),
            'nickname.required' => __('Enter a nickname.'),
            'nickname.min' => __('Your nickname needs :min to :max characters.', ['min' => self::NICKNAME_MIN, 'max' => self::NICKNAME_MAX]),
            'nickname.max' => __('Your nickname needs :min to :max characters.', ['min' => self::NICKNAME_MIN, 'max' => self::NICKNAME_MAX]),
        ];
    }

    public function render(): View
    {
        $photosOn = $this->photosOn();
        if (! $photosOn && $this->photo !== null) {
            $this->discardUpload(); // the code now points at a room without photos
        }

        try {
            $this->photoUrl = $this->photo instanceof TemporaryUploadedFile ? $this->photo->temporaryUrl() : null;
        } catch (Throwable) {
            $this->photoUrl = null;
        }

        return view('livewire.player.join-room', ['photosOn' => $photosOn])->title(__('Join a game'));
    }
}
