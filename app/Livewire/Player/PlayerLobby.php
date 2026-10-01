<?php

namespace App\Livewire\Player;

use App\Enums\RoomStatus;
use App\Game\PlayerIdentity;
use App\Game\RoomPresence;
use App\Game\RoomRoster;
use App\Models\Room;
use App\Models\RoomPlayer;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The phone while waiting for the host, live: the room's broadcast events and presence changes refresh the list, the Ready
 * toggle and Leave go through RoomRoster (which broadcasts). The participant is found from the request (user or guest
 * token) on every call, never from client state. If the row is gone (removed by the host, dropped after being offline),
 * the phone goes to the join screen with a message.
 */
#[Layout('layouts::lamma', ['realtime' => true])]
class PlayerLobby extends Component
{
    #[Locked]
    public Room $room;

    public function mount(Room $room): void
    {
        $this->room = $room;
        $this->useOwnLocale();
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
            "{$channel},GameStarted" => '$refresh',
            "{$channel},GameFinished" => '$refresh',
            "{$channel},RoomClosed" => 'roomClosed',
        ];
    }

    public function toggleReady(PlayerIdentity $identity, RoomRoster $roster): void
    {
        if ($player = $identity->playerIn($this->room)) {
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

        /** @var Collection<int, RoomPlayer> $players */
        $players = $this->room->players()->orderBy('joined_at')->orderBy('id')->get();
        $connected = $players->whereNull('left_at');

        return view('livewire.player.player-lobby', [
            'me' => $me,
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
