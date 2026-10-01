<?php

namespace App\Livewire\Player;

use App\Enums\RoomStatus;
use App\Game\PlayerIdentity;
use App\Models\Room;
use App\Models\RoomPlayer;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The phone while waiting for the host. Static in this phase: the Ready toggle saves is_ready, nothing is broadcast yet.
 * The participant is found from the request (user or guest token) on every call, never from client state.
 */
#[Layout('layouts::lamma')]
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
    }

    /** A phone shows the player's own language, whatever the browser or site language is. */
    private function useOwnLocale(): void
    {
        if ($player = app(PlayerIdentity::class)->playerIn($this->room)) {
            app()->setLocale($player->locale);
        }
    }

    public function toggleReady(PlayerIdentity $identity): void
    {
        $player = $identity->playerIn($this->room) ?? abort(403);

        if ($this->room->fresh()?->status !== RoomStatus::Lobby) {
            return;
        }

        $player->update(['is_ready' => ! $player->is_ready]);
    }

    public function render(PlayerIdentity $identity): View
    {
        $me = $identity->playerIn($this->room) ?? abort(403);

        /** @var Collection<int, RoomPlayer> $players */
        $players = $this->room->players()->orderBy('joined_at')->orderBy('id')->get();

        return view('livewire.player.player-lobby', [
            'me' => $me,
            'players' => $players,
            'myIndex' => (int) $players->search(fn (RoomPlayer $player) => $player->is($me)),
            'readyCount' => $players->where('is_ready', true)->count(),
            'inLobby' => $this->room->status === RoomStatus::Lobby,
        ])->title(__('Lobby').' '.$this->room->code);
    }
}
