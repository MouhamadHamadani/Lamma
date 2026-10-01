<?php

namespace App\Livewire\Host;

use App\Enums\HostScreenLocale;
use App\Enums\RoomStatus;
use App\Game\RoomManager;
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
 * The big screen while players join. Static in this phase: the list is read from the database on each render,
 * with no broadcasting yet. Only the room's host reaches it (RoomPolicy::host, checked again on every action).
 */
#[Layout('layouts::lamma')]
class HostLobby extends Component
{
    #[Locked]
    public Room $room;

    public function mount(Room $room): mixed
    {
        $this->room = $room;

        if ($room->status === RoomStatus::Finished) {
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

    public function closeRoom(RoomManager $rooms): mixed
    {
        $this->authorize('host', $this->room);

        $rooms->close($this->room);

        return $this->redirectRoute('rooms.create');
    }

    public function render(): View
    {
        $settings = $this->room->settings;

        /** @var Collection<int, RoomPlayer> $players */
        $players = $this->room->players()->orderBy('joined_at')->orderBy('id')->get();
        $joinUrl = route('join', ['code' => $this->room->code]);

        return view('livewire.host.host-lobby', [
            'players' => $players,
            'readyCount' => $players->where('is_ready', true)->count(),
            'both' => $settings->hostScreenLocale === HostScreenLocale::Both,
            'categoryNames' => Category::whereIn('id', $settings->categoryIds)->orderBy('sort_order')->orderBy('id')->get()->pluck('name'),
            'joinUrl' => $joinUrl,
            'joinAddress' => preg_replace('#^https?://#', '', route('join')),
            'qr' => QrCode::svg($joinUrl),
        ])->title(__('Lobby').' '.$this->room->code);
    }
}
