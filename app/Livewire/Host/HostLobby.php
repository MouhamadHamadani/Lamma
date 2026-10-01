<?php

namespace App\Livewire\Host;

use App\Enums\HostScreenLocale;
use App\Enums\RoomStatus;
use App\Game\GameEngine;
use App\Game\GameStartException;
use App\Game\PlayerIdentity;
use App\Game\RoomManager;
use App\Game\RoomPresence;
use App\Game\RoomRoster;
use App\Models\Category;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Support\QrCode;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The big screen while players join, live. It listens on the room's presence channel (Livewire's echo-presence listeners):
 * here/joining/leaving keep each player's left_at up to date (only the host does this, so a phone cannot mark another
 * phone as gone) and the broadcast events refresh the list. Only the room's host reaches it (RoomPolicy::host, checked
 * again on every action). The rules live in App\Game (RoomRoster, GameEngine).
 */
#[Layout('layouts::lamma', ['realtime' => true])]
class HostLobby extends Component
{
    #[Locked]
    public Room $room;

    /** Why the last Start click was refused. */
    public string $startError = '';

    /** The last join or leave, for the screen reader's live region ("Sara joined"). */
    public string $announcement = '';

    public function mount(Room $room): mixed
    {
        $this->room = $room;

        // A finished game shows its results (a refresh keeps them); a room closed before the game ran has nothing to show.
        if ($room->status === RoomStatus::Finished && ! $room->isCompleted()) {
            return $this->redirectRoute('rooms.create');
        }

        $this->useRoomLocale();

        return null;
    }

    public function hydrate(): void
    {
        $this->useRoomLocale();
    }

    /** The big screen follows the room's language setting; Both is left-to-right English with Arabic lines marked lang="ar" dir="rtl". */
    private function useRoomLocale(): void
    {
        app()->setLocale($this->room->settings->hostScreenLocale === HostScreenLocale::Ar ? 'ar' : 'en');
    }

    /** @return array<string, string> */
    public function getListeners(): array
    {
        $channel = 'echo-presence:'.RoomPresence::channel($this->room->code);

        return [
            "{$channel},here" => 'presenceHere',
            "{$channel},joining" => 'presenceJoining',
            "{$channel},leaving" => 'presenceLeaving',
            "{$channel},PlayerJoined" => 'playerJoined',
            "{$channel},PlayerLeft" => 'playerLeft',
            "{$channel},PlayerReadyChanged" => '$refresh',
        ];
    }

    /** @param  array<string, mixed>  $payload  the PlayerJoined event: {player: {nickname, ...}} */
    public function playerJoined(array $payload = []): void
    {
        if ($name = $this->nicknameIn($payload)) {
            $this->announcement = __(':name joined', ['name' => $name]);
        }
    }

    /** @param  array<string, mixed>  $payload  the PlayerLeft event */
    public function playerLeft(array $payload = []): void
    {
        if ($name = $this->nicknameIn($payload)) {
            $this->announcement = __(':name left', ['name' => $name]);
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function nicknameIn(array $payload): ?string
    {
        $name = $payload['player']['nickname'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * Everyone on the channel right now (sent when this screen subscribes, and again after a reconnect).
     *
     * @param  array<int, mixed>  $members
     */
    public function presenceHere(RoomRoster $roster, array $members = []): void
    {
        $this->authorize('host', $this->room);

        $roster->syncPresence($this->room, array_values(array_filter(array_map(fn ($member) => $this->playerId($member), $members))));
    }

    /** @param  array<string, mixed>  $member */
    public function presenceJoining(RoomRoster $roster, array $member = []): void
    {
        $this->authorize('host', $this->room);

        if ($id = $this->playerId($member)) {
            $roster->markConnected($this->room, $id);
        }
    }

    /** @param  array<string, mixed>  $member */
    public function presenceLeaving(RoomRoster $roster, array $member = []): void
    {
        $this->authorize('host', $this->room);

        if ($id = $this->playerId($member)) {
            $roster->markDisconnected($this->room, $id);
        }
    }

    /** Drop players who have been disconnected for over 30 seconds. Polled, so it also fixes anything a missed event left behind. */
    public function pruneDisconnected(RoomRoster $roster): void
    {
        $this->authorize('host', $this->room);

        $roster->pruneDisconnected($this->room);
    }

    public function removePlayer(RoomRoster $roster, PlayerIdentity $identity, int $playerId): void
    {
        $this->authorize('host', $this->room);

        $roster->remove($this->room, $identity->user() ?? abort(403), $playerId);
    }

    public function start(GameEngine $engine, PlayerIdentity $identity): void
    {
        $this->authorize('host', $this->room);

        try {
            $engine->start($this->room, $identity->user() ?? abort(403));
            $this->startError = '';
        } catch (GameStartException $e) {
            $this->startError = $e->getMessage();
        }

        $this->room->refresh();
    }

    public function closeRoom(RoomManager $rooms): mixed
    {
        $this->authorize('host', $this->room);

        $rooms->close($this->room);

        return $this->redirectRoute('rooms.create');
    }

    /** The player id in a presence member (the info the channel auth returned), or null for the host or junk. */
    private function playerId(mixed $member): ?int
    {
        $id = is_array($member) ? ($member['room_player_id'] ?? null) : null;

        return is_numeric($id) ? (int) $id : null;
    }

    public function render(): View
    {
        $settings = $this->room->settings;

        /** @var Collection<int, RoomPlayer> $players */
        $players = $this->room->players()->orderBy('joined_at')->orderBy('id')->get();
        $connected = $players->whereNull('left_at');
        $readyCount = $connected->where('is_ready', true)->count();
        $inLobby = $this->room->status === RoomStatus::Lobby;
        $canStart = $inLobby && $connected->isNotEmpty() && $readyCount === $connected->count();
        $joinUrl = route('join', ['code' => $this->room->code]);

        if ($canStart) {
            $this->startError = '';
        }

        return view('livewire.host.host-lobby', [
            'players' => $players,
            'connectedCount' => $connected->count(),
            'readyCount' => $readyCount,
            'canStart' => $canStart,
            'inLobby' => $inLobby,
            'both' => $settings->hostScreenLocale === HostScreenLocale::Both,
            'categoryNames' => Category::whereIn('id', $settings->categoryIds)->orderBy('sort_order')->orderBy('id')->get()->pluck('name'),
            'joinUrl' => $joinUrl,
            'joinAddress' => preg_replace('#^https?://#', '', route('join')),
            'qr' => QrCode::svg($joinUrl),
        ])->title(__('Lobby').' '.$this->room->code);
    }
}
