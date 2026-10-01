{{-- Reference: docs/design/screens/player-2-lobby.html. Designed at 390x844; a phone-width column on bigger screens.
     Static in this phase: the Ready toggle saves is_ready, nothing is broadcast. --}}
<div class="relative mx-auto flex min-h-dvh w-full max-w-md flex-col px-5 pb-[max(1.75rem,env(safe-area-inset-bottom))]">
    <header class="flex h-16 shrink-0 items-center justify-between">
        <a href="{{ route('home') }}"><x-lamma.logo size="sm" /></a>
        <x-lamma.room-code :code="$room->code" size="chip" data-test="room-code" />
    </header>

    <x-lamma.shape :index="2" :size="22" class="absolute start-8 top-24 rotate-[14deg] text-sun rtl:-rotate-[14deg]" />
    <x-lamma.shape :index="0" :size="20" class="absolute end-9 top-32 -rotate-12 text-coral rtl:rotate-12" />
    <x-lamma.shape :index="1" :size="12" class="absolute start-[72px] top-[200px] text-teal" />

    <div class="relative flex grow flex-col gap-5 pt-4">
        <div class="flex flex-col items-center gap-3 text-center">
            <x-lamma.avatar :name="$me->nickname" :color="$myIndex" :size="120" class="rounded-full shadow-sticker" />
            <h1 class="font-display text-[34px] font-extrabold leading-tight" data-test="welcome">
                {{-- The translation is trusted; the nickname is escaped and kept left-to-right inside it. --}}
                {!! __("You're in, :name!", ['name' => '<bdi dir="ltr">'.e($me->nickname).'</bdi>']) !!}
            </h1>
            <p class="text-base text-ink-muted">{{ $inLobby ? __('Waiting for the host to start the game.') : __('This game has already started') }}</p>
        </div>

        <section class="rounded-card border-2 border-line bg-white px-4 py-3.5" aria-labelledby="players-title">
            <div class="flex items-baseline justify-between pb-1">
                <h2 id="players-title" class="text-sm font-bold text-ink-subtle">{{ __('Players') }}</h2>
                <span class="text-sm font-bold text-ink-subtle" aria-live="polite" data-test="ready-count">{{ __(':ready of :total ready', ['ready' => $readyCount, 'total' => $players->count()]) }}</span>
            </div>
            <ul data-test="player-list">
                @foreach ($players as $index => $player)
                    <x-lamma.player-row :player="$player" :index="$index" size="phone" :show-language="false" :you="$player->is($me)" wire:key="player-{{ $player->id }}" />
                @endforeach
            </ul>
        </section>

        <div class="grow"></div>

        @if ($inLobby)
            <x-lamma.button
                :variant="$me->is_ready ? 'teal' : 'primary'" size="lg" :icon="$me->is_ready ? 'check' : null"
                class="min-h-[72px] w-full font-display text-2xl" wire:click="toggleReady"
                aria-pressed="{{ $me->is_ready ? 'true' : 'false' }}" data-test="ready-button"
            >{{ $me->is_ready ? __("I'm ready!") : __("I'm ready") }}</x-lamma.button>

            <p class="text-center text-base text-ink-muted">{{ $me->is_ready ? __('Tap again if you need a minute.') : __("Tap when you're ready to play.") }}</p>
        @endif
    </div>
</div>
