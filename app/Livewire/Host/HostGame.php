<?php

namespace App\Livewire\Host;

use App\Enums\HostScreenLocale;
use App\Enums\RoomStatus;
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

        return collect(['QuestionStarted', 'QuestionRevealed', 'ScoreboardUpdated', 'GameFinished', 'joining', 'leaving'])
            ->mapWithKeys(fn (string $event) => ["{$channel},{$event}" => '$refresh'])
            ->put("{$channel},PlayerAnswered", 'playerAnswered')
            ->all();
    }

    /**
     * Someone locked in an answer: the screen refreshes (their avatar gets the check) and a soft tick plays, a little higher with each answer.
     *
     * @param  array<string, mixed>  $payload  the PlayerAnswered event
     */
    public function playerAnswered(array $payload = []): void
    {
        $answered = is_numeric($payload['answered'] ?? null) ? (int) $payload['answered'] : 1;

        $this->dispatch('lamma-sound', name: 'tap', rate: round(1 + min(0.6, max(0, $answered - 1) * 0.06), 2));
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

        // A room closed part-way has no results to show.
        if ($state?->finished && ! $state->completed()) {
            $this->redirectRoute('rooms.create');
        }

        // Between "Start game" and question 1 there is no question yet: the 3 - 2 - 1 - Go! countdown shows (it ends at countdown_ends_at).
        $countdownEndsAt = $state === null && $room->status === RoomStatus::Playing ? $room->countdown_ends_at : null;

        return view('livewire.host.host-game', [
            'state' => $state,
            'countdownEndsAt' => $countdownEndsAt,
            'both' => $mode === HostScreenLocale::Both,
            // The language of the main line; Both is English with Arabic lines under it.
            'main' => $mode === HostScreenLocale::Ar ? 'ar' : 'en',
            'tone' => $state ? CategoryStyle::tintName($state->category->tintSlug()) : null,
            'icon' => $state ? CategoryStyle::icon($state->category->slug) : null,
            // Milliseconds until the next thing is due, relative, so the browser's clock does not matter.
            'dueInMs' => $state ? $this->dueInMs($state, $engine) : ($countdownEndsAt ? max(0, (int) now()->diffInMilliseconds($countdownEndsAt, false)) : null),
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
