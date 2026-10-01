<?php

namespace App\Livewire\Player;

use App\Enums\RoomStatus;
use App\Game\PlayerIdentity;
use App\Game\RoomPresence;
use App\Game\Scoreboard;
use App\Models\Room;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The phone after the last question (player-6): the player's place on a tilted tile, their score, the leaderboard with their own row
 * outlined, and what to do next. A guest is offered to save the game to a profile (log in or sign up, then ClaimGuestResults attaches
 * it); a logged-in player sees it is saved. When the host presses "Play again" the phone follows to the new lobby by itself, either
 * from the RoomRestarted broadcast or, if that was missed, from the poll: the old room remembers the new one (rooms.next_room_id).
 * Nothing here deletes the player's row, so saved results survive "Leave room".
 */
class PlayerResults extends Component
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

        return ["{$channel},RoomRestarted" => 'follow'];
    }

    /**
     * Go to the new lobby if the host started another round and this player is in it. Called by the broadcast and by the poll; it
     * reads the database, never the event payload.
     */
    public function follow(PlayerIdentity $identity): mixed
    {
        $next = $this->room->nextRoom;

        if ($next !== null && $next->status !== RoomStatus::Finished && $identity->playerIn($next) !== null) {
            return $this->redirectRoute('play', ['room' => $next->code], navigate: false);
        }

        return null;
    }

    public function render(PlayerIdentity $identity, Scoreboard $scoreboard): View
    {
        $me = $identity->playerIn($this->room) ?? abort(403);
        $ranking = $scoreboard->ranking($this->room);
        $row = collect($ranking)->firstWhere('player.id', $me->id);
        $rank = $row['rank'] ?? count($ranking);

        return view('livewire.player.player-results', [
            'me' => $me,
            'ranking' => $ranking,
            'rank' => $rank,
            'total' => $row['total'] ?? $me->score,
            'tied' => collect($ranking)->where('rank', $rank)->count() > 1,
            'correct' => $me->answers()->where('is_correct', true)->count(),
            'questions' => $this->room->roomQuestions()->count(),
            // Saved = tied to an account. A guest can save it by logging in; a logged-in player whose row is still a guest row (played
            // before logging in, claim window over) cannot.
            'saved' => $me->user_id !== null,
            'loggedIn' => $identity->user() !== null,
            'colors' => $this->room->players()->orderBy('joined_at')->orderBy('id')->pluck('id')->flip()->all(),
        ]);
    }
}
