<?php

namespace App\Livewire\Host;

use App\Enums\HostScreenLocale;
use App\Game\GameEngine;
use App\Game\GameState;
use App\Game\GameView;
use App\Game\RoomManager;
use App\Game\RoomPresence;
use App\Models\Room;
use App\Support\CategoryStyle;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The big screen during a game (host-4 question, host-5 reveal), nested in HostLobby so the host keeps one URL. It draws
 * whatever the database says is current (GameView), so a refresh or a reconnect lands where the game is. Broadcast events
 * refresh it; a timer set for the moment something is due, and a slow poll, call tick() so the game keeps moving when the
 * queue worker is late or not running. Every action re-checks that the caller is the room's host.
 */
class HostGame extends Component
{
    #[Locked]
    public Room $room;

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

    /** @return array<string, string> */
    public function getListeners(): array
    {
        $channel = 'echo-presence:'.RoomPresence::channel($this->room->code);

        return collect(['QuestionStarted', 'PlayerAnswered', 'QuestionRevealed', 'ScoreboardUpdated', 'GameFinished', 'joining', 'leaving'])
            ->mapWithKeys(fn (string $event) => ["{$channel},{$event}" => '$refresh'])
            ->all();
    }

    /** Do whatever is due (reveal a question whose time is up, move on after the pause). Does nothing if it is not due yet. */
    public function tick(GameEngine $engine): void
    {
        $this->authorize('host', $this->room);

        $engine->tick($this->room);
    }

    /** "Skip timer": show the answer of this question now. The position is the one on screen, so a late click does nothing. */
    public function skipTimer(GameEngine $engine, int $position): void
    {
        $this->authorize('host', $this->room);

        $engine->reveal($this->room, $position, force: true);
    }

    /** "Next question": move on without waiting out the pause. The position is the one on screen, so a double click does nothing. */
    public function next(GameEngine $engine, int $position): void
    {
        $this->authorize('host', $this->room);

        $engine->advance($this->room, $position, force: true);
    }

    public function closeRoom(RoomManager $rooms): mixed
    {
        $this->authorize('host', $this->room);

        $rooms->close($this->room);

        return $this->redirectRoute('rooms.create');
    }

    public function render(GameView $view, GameEngine $engine): View
    {
        $room = $this->room->fresh() ?? $this->room;
        $state = $view->forRoom($room);
        $mode = $room->settings->hostScreenLocale;

        return view('livewire.host.host-game', [
            'state' => $state,
            'both' => $mode === HostScreenLocale::Both,
            // The language of the main line; Both is English with Arabic lines under it.
            'main' => $mode === HostScreenLocale::Ar ? 'ar' : 'en',
            'tone' => $state ? CategoryStyle::tintName($state->category->slug) : null,
            'icon' => $state ? CategoryStyle::icon($state->category->slug) : null,
            // Milliseconds until the next thing is due, relative, so the browser's clock does not matter.
            'dueInMs' => $state ? $this->dueInMs($state, $engine) : null,
        ]);
    }

    /** When tick() has work to do: a question's reveal is due ends_at + grace, a reveal's follow-up after the pause. */
    private function dueInMs(GameState $state, GameEngine $engine): ?int
    {
        $question = $state->roomQuestion;
        $due = $state->isRevealed()
            ? $question->revealed_at?->addSeconds(GameEngine::REVEAL_SECONDS)
            : $engine->revealDueAt($question);

        return $state->finished || $due === null ? null : max(0, (int) now()->diffInMilliseconds($due, false));
    }
}
