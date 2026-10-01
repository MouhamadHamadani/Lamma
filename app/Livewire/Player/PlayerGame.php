<?php

namespace App\Livewire\Player;

use App\Game\AnswerRejected;
use App\Game\GameEngine;
use App\Game\GameView;
use App\Game\PlayerIdentity;
use App\Game\RoomPresence;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Support\CategoryStyle;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The phone during a game (player-3 question, player-4 answered, player-5 correct / not quite / time's up), nested in PlayerLobby so the
 * player keeps one URL. It draws what the database says is current (GameView) for THIS player: their own answer, score and rank, in
 * their own language. The broadcast events and a slow poll refresh it; a reconnect or a page reload just lands in the same place.
 * The player is found from the request (user or guest token) on every call, never from client state.
 */
class PlayerGame extends Component
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

        // Removed from the room (or never in it): back to the join screen rather than an error.
        if (app(PlayerIdentity::class)->playerIn($this->room) === null) {
            session()->flash('notice', __("You're not in this room. Join again with the code."));
            $this->redirectRoute('join', ['code' => $this->room->code]);
            $this->skipRender();
        }
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

        return collect(['here', 'QuestionStarted', 'PlayerAnswered', 'QuestionRevealed', 'ScoreboardUpdated', 'GameFinished'])
            ->mapWithKeys(fn (string $event) => ["{$channel},{$event}" => '$refresh'])
            ->all();
    }

    /**
     * Lock in an answer. No confirm step: one tap and it is final. A late, repeated or out-of-turn tap is refused by the engine
     * and the screen simply shows the game as it is.
     */
    public function answer(GameEngine $engine, PlayerIdentity $identity, int $optionId): void
    {
        $player = $identity->playerIn($this->room) ?? abort(403);

        try {
            $engine->submitAnswer($player, $optionId);
        } catch (AnswerRejected) {
            // nothing to add: render() shows the current state
        }
    }

    public function render(GameView $view, PlayerIdentity $identity): View
    {
        $me = $identity->playerIn($this->room) ?? abort(403);
        $room = $this->room->fresh() ?? $this->room;
        $state = $view->forRoom($room);
        $answer = $state?->answerOf($me);
        $revealed = (bool) $state?->isRevealed();

        // What the player sees: a question to answer, their locked answer, or the outcome.
        $phase = match (true) {
            $state === null => 'waiting',
            $state->finished => $state->completed() ? 'over' : 'closed',
            ! $revealed => $answer ? 'answered' : 'question',
            $answer?->is_correct => 'correct',
            $answer !== null => 'wrong',
            default => 'timeout',
        };

        return view('livewire.player.player-game', [
            'me' => $me->fresh() ?? $me,
            'state' => $state,
            'phase' => $phase,
            'answer' => $answer,
            'lang' => $me->locale,
            'icon' => $state ? CategoryStyle::icon($state->category->slug) : null,
            'row' => $state ? $state->rankingRow($me) : null,
            'myIndex' => (int) ($state?->players->search(fn (RoomPlayer $player) => $player->is($me)) ?: 0),
        ]);
    }
}
