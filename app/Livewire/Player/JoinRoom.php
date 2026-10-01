<?php

namespace App\Livewire\Player;

use App\Game\JoinException;
use App\Game\PlayerIdentity;
use App\Game\RoomCodeGenerator;
use App\Game\RoomJoiner;
use App\Models\Room;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** A phone enters a room code and a nickname. Who may join, and under which name, is decided by App\Game\RoomJoiner. */
#[Layout('layouts::lamma')]
class JoinRoom extends Component
{
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

        try {
            $joiner->join($this->code, $this->nickname, $this->language);
        } catch (JoinException $e) {
            $this->addError($e->field, $e->getMessage());

            return null;
        }

        return $this->redirectRoute('play', ['room' => $this->code]);
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
        return view('livewire.player.join-room')->title(__('Join a game'));
    }
}
