@use('App\Enums\RoomStatus')
{{-- Reference: docs/design/screens/player-2-lobby.html. Designed at 390x844; a phone-width column on bigger screens. Live.
     The 5 s poll is the safety net for a phone whose socket dropped (a locked screen, a bad signal): it still finds out that the game
     started, that the host removed it or that the room closed. The game screen has its own poll. --}}
<div @if ($inLobby) wire:poll.5s @endif>
@if ($inGame)
    {{-- The game: its own component (PlayerGame) owns the whole screen. --}}
    <livewire:player.player-game :room="$room" :key="'game-'.$room->id" />
@else
<div class="relative mx-auto flex min-h-dvh w-full max-w-md flex-col px-5 pb-[max(1.75rem,env(safe-area-inset-bottom))]">
    <header class="flex h-16 shrink-0 items-center justify-between">
        <a href="{{ route('home') }}"><x-lamma.logo size="sm" /></a>
        <x-lamma.room-code :code="$room->code" size="chip" data-test="room-code" />
    </header>

    @if ($status === RoomStatus::Finished)
        <main class="flex grow flex-col items-center justify-center gap-6 text-center" data-test="closed">
            <h1 class="font-display text-[34px] font-extrabold leading-tight">{{ __('This room has been closed.') }}</h1>
            <x-lamma.button :href="route('home')" variant="outline">{{ __('Back to home') }}</x-lamma.button>
        </main>
    @else
        <x-lamma.shape :index="2" :size="22" class="absolute start-8 top-24 rotate-[14deg] text-sun rtl:-rotate-[14deg]" />
        <x-lamma.shape :index="0" :size="20" class="absolute end-9 top-32 -rotate-12 text-coral rtl:rotate-12" />
        <x-lamma.shape :index="1" :size="12" class="absolute start-[72px] top-[200px] text-teal" />

        <div class="relative flex grow flex-col gap-5 pt-4">
            <div class="flex flex-col items-center gap-3 text-center">
                @if ($inLobby)
                    {{-- Tap the avatar to change the name and photo until the game starts. --}}
                    <button
                        type="button" wire:click="toggleProfile" aria-controls="profile-panel" aria-expanded="{{ $editing || $choosing ? 'true' : 'false' }}"
                        aria-label="{{ $photosOn ? __('Change your name or photo') : __('Change your name') }}" class="relative min-h-11 min-w-11 rounded-full" data-test="avatar-button"
                    >
                        <x-lamma.avatar :name="$me->nickname" :color="$myIndex" :size="120" :photo="$photosOn ? $me->avatarUrl() : null" :lazy="false" class="rounded-full shadow-sticker" />
                        <span aria-hidden="true" class="absolute -bottom-1 -end-1 flex size-9 items-center justify-center rounded-full border-3 border-navy bg-white text-navy">
                            <x-lamma.icon :name="$photosOn ? 'camera' : 'user'" :size="18" />
                        </span>
                    </button>
                @else
                    <x-lamma.avatar :name="$me->nickname" :color="$myIndex" :size="120" :photo="$photosOn ? $me->avatarUrl() : null" :lazy="false" class="rounded-full shadow-sticker" />
                @endif
                <h1 class="font-display text-[34px] font-extrabold leading-tight" data-test="welcome">
                    {{-- The translation is trusted; the nickname is escaped and kept left-to-right inside it. --}}
                    {!! __("You're in, :name!", ['name' => '<bdi dir="ltr">'.e($me->nickname).'</bdi>']) !!}
                </h1>
                <p class="text-base text-ink-muted">{{ __('Waiting for the host to start the game.') }}</p>
                @if ($photoNotice)
                    <p class="flex items-center gap-1.5 text-sm font-semibold text-coral-700" role="status" data-test="photo-notice"><x-lamma.icon name="alert" :size="18" />{{ $photoNotice }}</p>
                @endif
                <p class="sr-only" role="status" aria-live="polite" data-test="profile-announcer">{{ $announcement }}</p>
            </div>

            @if ($inLobby && ($choosing || $editing))
                <section id="profile-panel" class="flex flex-col gap-4 rounded-card border-2 border-line bg-white px-4 py-4" aria-label="{{ $photosOn ? __('Your name and photo') : __('Your name') }}" data-test="profile-panel">
                    @if ($choosing)
                        <p class="text-center text-base font-semibold">{{ $photosOn ? __('Keep your name and photo for this round, or pick new ones.') : __('Keep your name for this round, or pick a new one.') }}</p>
                        <div class="flex gap-3">
                            <x-lamma.button wire:click="keepProfile" class="grow px-4" data-test="profile-keep">{{ __('Keep') }}</x-lamma.button>
                            <x-lamma.button variant="outline" wire:click="changeProfile" class="grow px-4" data-test="profile-change">{{ __('Change') }}</x-lamma.button>
                        </div>
                    @else
                        <form wire:submit="saveName" class="flex flex-col gap-2" novalidate>
                            <label for="lobby-nickname" class="text-[15px] font-bold">{{ __('Your nickname') }}</label>
                            <div class="flex gap-2">
                                <input
                                    id="lobby-nickname" type="text" dir="ltr" wire:model="nickname" maxlength="20" autocomplete="nickname" data-test="profile-nickname"
                                    @if ($errors->has('nickname') || $nameError) aria-invalid="true" aria-describedby="lobby-nickname-error" @endif
                                    class="h-14 min-w-0 grow rounded-btn border-2 bg-white px-[18px] text-lg font-semibold text-navy {{ $errors->has('nickname') || $nameError ? 'border-coral-700' : 'border-line' }}"
                                >
                                <x-lamma.button type="submit" variant="outline" class="shrink-0 px-5" wire:loading.attr="disabled" wire:target="saveName" data-test="profile-save-name">{{ __('Save') }}</x-lamma.button>
                            </div>
                            @error('nickname')
                                <x-lamma.error id="lobby-nickname-error" data-test="profile-name-error">{{ $message }}</x-lamma.error>
                            @elseif ($nameError)
                                <x-lamma.error id="lobby-nickname-error" data-test="profile-name-error">{{ $nameError }}</x-lamma.error>
                            @enderror
                        </form>

                        @if ($photosOn)
                            <x-lamma.selfie :preview="false" />
                        @endif

                        <button type="button" wire:click="toggleProfile" class="min-h-11 self-center px-3 text-[15px] font-semibold text-ink-muted underline underline-offset-4" data-test="profile-close">{{ __('Done') }}</button>
                    @endif
                </section>
            @endif

            <section class="rounded-card border-2 border-line bg-white px-4 py-3.5" aria-labelledby="players-title">
                <div class="flex items-baseline justify-between pb-1">
                    <h2 id="players-title" class="text-sm font-bold text-ink-subtle">{{ __('Players') }}</h2>
                    <span class="text-sm font-bold text-ink-subtle" aria-live="polite" data-test="ready-count">{{ __(':ready of :total ready', ['ready' => $readyCount, 'total' => $connectedCount]) }}</span>
                </div>
                <ul data-test="player-list">
                    @foreach ($players as $index => $player)
                        <x-lamma.player-row :player="$player" :index="$index" size="phone" :show-language="false" :you="$player->is($me)" wire:key="player-{{ $player->id }}" />
                    @endforeach
                </ul>
            </section>

            <div class="grow"></div>

            <x-lamma.button
                :variant="$me->is_ready ? 'teal' : 'primary'" size="lg" :icon="$me->is_ready ? 'check' : null"
                class="min-h-[72px] w-full font-display text-2xl" wire:click="toggleReady"
                aria-pressed="{{ $me->is_ready ? 'true' : 'false' }}" data-test="ready-button"
            >{{ $me->is_ready ? __("I'm ready!") : __("I'm ready") }}</x-lamma.button>

            <p class="text-center text-base text-ink-muted">{{ $me->is_ready ? __('Tap again if you need a minute.') : __("Tap when you're ready to play.") }}</p>

            <button
                type="button" wire:click="leave" wire:confirm="{{ __('Leave this room?') }}" data-test="leave-button"
                class="min-h-11 self-center px-3 text-[15px] font-semibold text-ink-muted underline underline-offset-4 hover:text-coral-700"
            >{{ __('Leave room') }}</button>
        </div>
    @endif
</div>
@endif

    <x-lamma.reconnecting />
</div>
