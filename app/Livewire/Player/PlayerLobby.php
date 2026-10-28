<?php

namespace App\Livewire\Player;

use App\Enums\RoomStatus;
use App\Game\JoinException;
use App\Game\PhotoException;
use App\Game\PlayerIdentity;
use App\Game\RoomPresence;
use App\Game\RoomRoster;
use App\Livewire\Player\Concerns\HasSelfie;
use App\Models\Room;
use App\Models\RoomPlayer;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The phone while waiting for the host, live: the room's broadcast events and presence changes refresh the list, the Ready
 * toggle and Leave go through RoomRoster (which broadcasts). The participant is found from the request (user or guest
 * token) on every call, never from client state. If the row is gone (removed by the host, dropped after being offline),
 * the phone goes to the join screen with a message.
 */
#[Layout('layouts::lamma', ['realtime' => true])]
class PlayerLobby extends Component
{
    use HasSelfie;

    #[Locked]
    public Room $room;

    /** The name field of the profile panel (the saved name is the row's). */
    public string $nickname = '';

    public string $nameError = '';

    /** The profile panel (name and photo) is open: tap the avatar. */
    public bool $editing = false;

    /** First visit to a rematch lobby: Keep or Change before Ready (the name and photo are the last game's, as a suggestion). */
    public bool $choosing = false;

    /** "The host removed your photo", until the player picks another or leaves. */
    public string $photoNotice = '';

    /** Read out by screen readers ("Name saved"). */
    public string $announcement = '';

    public function mount(Room $room): void
    {
        $this->room = $room;
        $this->useOwnLocale();

        if ($player = app(PlayerIdentity::class)->playerIn($room)) {
            $this->nickname = $player->nickname;
            $this->choosing = $room->status === RoomStatus::Lobby && $room->previous()->exists() && ! session()->has($this->seenKey());
        }
    }

    private function seenKey(): string
    {
        return "lobby.profile-seen.{$this->room->id}";
    }

    protected function photosOn(): bool
    {
        return $this->room->settings->showPhotos;
    }

    /** Save the upload as this player's photo (the others are told) and let the temporary file go. */
    protected function acceptPhoto(TemporaryUploadedFile $file): void
    {
        $player = app(PlayerIdentity::class)->playerIn($this->room) ?? throw PhotoException::unavailable();

        app(RoomRoster::class)->setPhoto($player, $file);
        $this->discardUpload($file);
        $this->photoNotice = '';
    }

    public function removePhoto(PlayerIdentity $identity, RoomRoster $roster): void
    {
        if (($player = $identity->playerIn($this->room)) && $roster->removePhoto($player)) {
            $this->photoError = '';
            $this->dispatch('photo-status', kind: 'removed');
        }
    }

    /** Tap the avatar: open or close the profile panel. */
    public function toggleProfile(): void
    {
        $this->editing = ! $this->editing;
        $this->choosing = false;
        $this->nameError = '';
        session()->put($this->seenKey(), true);
    }

    /** "Keep": the last game's name and photo stay. */
    public function keepProfile(): void
    {
        $this->choosing = false;
        $this->editing = false;
        session()->put($this->seenKey(), true);
    }

    /** "Change": open the panel to pick a new name and photo. */
    public function changeProfile(): void
    {
        $this->choosing = false;
        $this->editing = true;
        session()->put($this->seenKey(), true);
    }

    public function saveName(PlayerIdentity $identity, RoomRoster $roster): void
    {
        $this->nameError = '';
        $this->nickname = Str::squish($this->nickname);
        $this->validateOnly('nickname', [
            'nickname' => ['required', 'string', 'min:'.JoinRoom::NICKNAME_MIN, 'max:'.JoinRoom::NICKNAME_MAX],
        ], [
            'nickname.required' => __('Enter a nickname.'),
            'nickname.min' => __('Your nickname needs :min to :max characters.', ['min' => JoinRoom::NICKNAME_MIN, 'max' => JoinRoom::NICKNAME_MAX]),
            'nickname.max' => __('Your nickname needs :min to :max characters.', ['min' => JoinRoom::NICKNAME_MIN, 'max' => JoinRoom::NICKNAME_MAX]),
        ]);

        if ($player = $identity->playerIn($this->room)) {
            try {
                $this->announcement = $roster->rename($player, $this->nickname) ? __('Name saved') : '';
            } catch (JoinException $e) {
                $this->nameError = $e->getMessage();
            }
        }
    }

    public function hydrate(): void
    {
        $this->useOwnLocale();

        if (app(PlayerIdentity::class)->playerIn($this->room) === null) {
            $this->removedFromRoom();
        }
    }

    /** A phone shows the player's own language, whatever the browser or site language is. */
    private function useOwnLocale(): void
    {
        if ($player = app(PlayerIdentity::class)->playerIn($this->room)) {
            app()->setLocale($player->locale);
        }
    }

    /** @return array<string, string> */
    public function getListeners(): array
    {
        $channel = 'echo-presence:'.RoomPresence::channel($this->room->code);

        return [
            "{$channel},here" => '$refresh',
            "{$channel},joining" => '$refresh',
            "{$channel},leaving" => '$refresh',
            "{$channel},PlayerJoined" => '$refresh',
            "{$channel},PlayerLeft" => '$refresh',
            "{$channel},PlayerReadyChanged" => '$refresh',
            "{$channel},PlayerUpdated" => '$refresh',
            "{$channel},GameStarted" => '$refresh',
            "{$channel},GameFinished" => '$refresh',
            "{$channel},RoomClosed" => 'roomClosed',
        ];
    }

    public function toggleReady(PlayerIdentity $identity, RoomRoster $roster): void
    {
        if ($player = $identity->playerIn($this->room)) {
            $this->choosing = false;
            session()->put($this->seenKey(), true);
            $roster->toggleReady($player);
        }
    }

    public function leave(PlayerIdentity $identity, RoomRoster $roster): mixed
    {
        if ($player = $identity->playerIn($this->room)) {
            $roster->leave($player);
        }

        return $this->redirect(route('home'));
    }

    /** The host closed the room: say so on the way home. */
    public function roomClosed(): mixed
    {
        session()->flash('notice', __('The host closed this room.'));

        return $this->redirect(route('home'));
    }

    private function removedFromRoom(): void
    {
        session()->flash('notice', __("You're not in this room. Join again with the code."));
        $this->redirectRoute('join', ['code' => $this->room->code]);
        $this->skipRender();
    }

    public function render(PlayerIdentity $identity): View
    {
        $me = $identity->playerIn($this->room) ?? abort(403);
        $photosOn = $this->photosOn();
        $this->photoUrl = $photosOn ? $me->avatarUrl() : null;
        if ($photosOn && RoomRoster::wasRemovedByHost($me->id)) {
            $this->photoNotice = __('The host removed your photo');
        }

        /** @var Collection<int, RoomPlayer> $players */
        $players = $this->room->players()->orderBy('joined_at')->orderBy('id')->get();
        $connected = $players->whereNull('left_at');

        return view('livewire.player.player-lobby', [
            'me' => $me,
            'photosOn' => $photosOn,
            'players' => $players,
            'myIndex' => (int) $players->search(fn (RoomPlayer $player) => $player->is($me)),
            'readyCount' => $connected->where('is_ready', true)->count(),
            'connectedCount' => $connected->count(),
            'status' => $this->room->status,
            'inLobby' => $this->room->status === RoomStatus::Lobby,
            // Playing, or finished after a game (PlayerGame shows the end); a room closed in the lobby is just closed.
            'inGame' => $this->room->status === RoomStatus::Playing || ($this->room->status === RoomStatus::Finished && $this->room->currentQuestion() !== null),
        ])->title(__('Lobby').' '.$this->room->code);
    }
}
