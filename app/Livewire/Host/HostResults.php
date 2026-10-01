<?php

namespace App\Livewire\Host;

use App\Enums\HostScreenLocale;
use App\Game\NotEnoughQuestions;
use App\Game\PlayerIdentity;
use App\Game\Podium;
use App\Game\RoomManager;
use App\Game\Scoreboard;
use App\Models\Room;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The big screen after the last question (host-6 podium), nested in HostLobby like the other host screens: who won, the podium (ties
 * share a step, fewer than three players have fewer steps), and what to do next. "Play again" makes a new room with the same
 * settings and the players who are still connected, and every phone follows it there (RoomManager::playAgain); "New game" goes to
 * the create-room screen. Only the room's host, checked again on every action.
 */
class HostResults extends Component
{
    #[Locked]
    public Room $room;

    /** Why "Play again" did not work. */
    public string $error = '';

    public function mount(Room $room): void
    {
        $this->room = $room;
        $this->useRoomLocale();
    }

    public function hydrate(): void
    {
        $this->useRoomLocale();
    }

    private function useRoomLocale(): void
    {
        app()->setLocale($this->room->settings->hostScreenLocale === HostScreenLocale::Ar ? 'ar' : 'en');
    }

    public function playAgain(RoomManager $rooms, PlayerIdentity $identity): mixed
    {
        $this->authorize('host', $this->room);

        try {
            $new = $rooms->playAgain($this->room, $identity->user() ?? abort(403));
        } catch (NotEnoughQuestions $e) {
            $this->error = __('Only :available playable questions are left for a game of :needed.', ['available' => $e->available, 'needed' => $e->needed]);

            return null;
        }

        return $this->redirectRoute('host.lobby', ['room' => $new->code], navigate: false);
    }

    public function render(Scoreboard $scoreboard, Podium $podium): View
    {
        $ranking = $scoreboard->ranking($this->room);
        $steps = $podium->steps($ranking);

        return view('livewire.host.host-results', [
            'both' => $this->room->settings->hostScreenLocale === HostScreenLocale::Both,
            'questionCount' => $this->room->roomQuestions()->count(),
            'winners' => $podium->winners($ranking),
            // On the podium the second step stands left of the first, the third right of it; a step nobody stands on is left out.
            'steps' => collect($steps)->sortBy(fn (array $step) => [2 => 0, 1 => 1, 3 => 2][$step['rank']])->values()->all(),
            'colors' => $this->room->players()->orderBy('joined_at')->orderBy('id')->pluck('id')->flip()->all(),
        ]);
    }
}
