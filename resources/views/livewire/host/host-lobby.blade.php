{{-- Reference: docs/design/screens/host-3-lobby.html. Live: presence + broadcast events refresh it, and wire:poll drops players who stay
     disconnected over 30 s. Both = left-to-right English with the Arabic lines marked lang="ar" dir="rtl". --}}
<div @class(['flex flex-col', 'min-h-dvh' => $inLobby]) @if ($inLobby) wire:poll.5s="pruneDisconnected" @endif>
@if (! $inLobby)
    {{-- The game: its own component (HostGame) owns the whole screen. This one keeps the presence bookkeeping. --}}
    <livewire:host.host-game :room="$room" :key="'game-'.$room->id" />
@else
    <header class="flex h-22 shrink-0 items-center justify-between gap-4 border-b-2 border-line bg-white px-5 lg:px-14">
        <a href="{{ route('home') }}"><x-lamma.logo /></a>

        <x-lamma.chip tone="sun" size="md">{{ __('Lobby') }}</x-lamma.chip>

        <div class="flex items-center gap-3">
            <x-lamma.chip size="lg" class="hidden xl:inline-flex">{{ $categoryNames->join(' · ') }}</x-lamma.chip>
            <x-lamma.chip size="lg" class="hidden lg:inline-flex">
                {{ __(':count questions', ['count' => $room->settings->questionCount]) }} · {{ __(':seconds s', ['seconds' => $room->settings->secondsPerQuestion]) }}
            </x-lamma.chip>
            <button
                type="button" wire:click="closeRoom" wire:confirm="{{ __('Close this room? Everyone in it will be disconnected.') }}"
                class="min-h-11 px-2 text-[15px] font-semibold text-coral-700 hover:underline" data-test="close-room"
            >{{ __('Close room') }}</button>
        </div>
    </header>

        <main class="flex grow flex-col gap-9 px-5 py-9 lg:flex-row lg:px-14 lg:pb-11">
            <section class="relative flex grow flex-col justify-between gap-8 overflow-hidden rounded-panel border-2 border-line bg-white p-6 lg:p-12">
                <x-lamma.confetti :count="3" />

                <div class="relative flex flex-col gap-2.5">
                    <span class="text-[15px] font-bold uppercase tracking-[1.5px] text-coral-700">{{ __('Join on your phone') }}</span>
                    <h1 class="font-display text-[clamp(30px,3vw,44px)] font-extrabold leading-tight">
                        {{ __('Go to') }}
                        <span dir="ltr" class="inline-block underline decoration-sun decoration-[.3em] underline-offset-[-.12em] [text-decoration-skip-ink:none]" data-test="join-address">{{ $joinAddress }}</span>
                    </h1>
                    <p class="text-xl text-ink-muted">{{ __('and enter this room code:') }}</p>
                    @if ($both)
                        <p><span lang="ar" dir="rtl" class="inline-block text-xl text-ink-muted">{{ __('and enter this room code:', [], 'ar') }}</span></p>
                    @endif
                </div>

                <div class="relative flex flex-wrap items-center justify-between gap-6">
                    <x-lamma.room-code :code="$room->code" data-test="room-code" />

                    <div
                        class="size-44 shrink-0 rounded-[20px] border-3 border-navy bg-white p-3 text-navy shadow-sticker-sm"
                        role="img" aria-label="{{ __('QR code to join this room') }}" data-test="qr-code" data-url="{{ $joinUrl }}"
                    >{!! $qr !!}</div>
                </div>

                <p class="relative flex items-center gap-2.5 text-base text-ink-muted">
                    <x-lamma.icon name="globe" :size="22" />
                    {{ __('Each player picks Arabic or English when they join.') }}
                </p>
            </section>

            <aside class="flex flex-col gap-3.5 lg:w-[460px] lg:shrink-0">
                <div class="flex items-baseline justify-between px-1 pb-1">
                    <h2 class="font-display text-[32px] font-extrabold">{{ __('Players') }} <span dir="ltr" class="text-ink-subtle" data-test="player-count">{{ $connectedCount }}</span></h2>
                    <span class="text-base font-semibold text-ink-muted" aria-live="polite" data-test="ready-count">{{ __(':ready of :total ready', ['ready' => $readyCount, 'total' => $connectedCount]) }}</span>
                </div>

                <ul class="flex flex-col gap-3.5" data-test="player-list">
                    @foreach ($players as $index => $player)
                        <x-lamma.player-row :player="$player" :index="$index" wire:key="player-{{ $player->id }}">
                            <x-slot:actions>
                                <button
                                    type="button" wire:click="removePlayer({{ $player->id }})" data-test="remove-player"
                                    aria-label="{{ __('Remove :name', ['name' => $player->nickname]) }}"
                                    class="flex size-9 shrink-0 items-center justify-center rounded-full text-ink-subtle hover:bg-tint-coral hover:text-coral-700"
                                ><x-lamma.icon name="x" :size="18" :stroke="2.4" /></button>
                            </x-slot:actions>
                        </x-lamma.player-row>
                    @endforeach

                    {{-- Always last, even with players. --}}
                    <li class="flex h-19 items-center justify-center gap-2.5 rounded-[20px] border-2 border-dashed border-line-strong text-base font-semibold text-ink-subtle">
                        <x-lamma.icon name="phone" :size="22" />
                        {{ __('Waiting for more players…') }}
                    </li>
                </ul>

                <div class="grow"></div>

                <x-lamma.button
                    size="lg" icon="play" :disabled="! $canStart" class="w-full"
                    wire:click="start" wire:loading.attr="disabled" wire:target="start" data-test="start-button"
                >{{ __('Start game') }}</x-lamma.button>
                @if ($startError)
                    <x-lamma.error data-test="start-error">{{ $startError }}</x-lamma.error>
                @endif
                <p class="text-sm text-ink-subtle">{{ __('Unlocks when everyone taps Ready.') }}</p>
            </aside>
        </main>
@endif

    <x-lamma.reconnecting />
</div>
