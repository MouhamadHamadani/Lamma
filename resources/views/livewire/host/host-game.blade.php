{{-- References: docs/design/screens/host-4-question.html (a question is open) and host-5-reveal.html (the answer). The screen is the database's
     current state (GameView), refreshed by the broadcast events and a 2 s poll; a timer set for the moment the next thing is due calls tick().
     Both = English main line with the Arabic line under it; one language otherwise. Nothing here scrolls (heights clamp to the screen). --}}
@php
    $gutter = 'px-[clamp(20px,3.9vw,56px)]';
    $tr = fn ($model, string $attribute, string $lang) => $model->getTranslation($attribute, $lang, false);
    $position = $state?->position();
    $revealed = (bool) $state?->isRevealed();
    $colors = $state ? $state->players->values()->mapWithKeys(fn ($player, $index) => [$player->id => $index])->all() : [];
    $seconds = $room->settings->secondsPerQuestion;
    $nowMs = now()->getTimestampMs();
@endphp
<div>
@if ($state?->finished)
    {{-- The game is over: the results screen (podium, Play again) takes the whole screen. --}}
    <livewire:host.host-results :room="$room" :key="'results-'.$room->id" />
@else
<div class="flex h-dvh flex-col overflow-hidden" wire:poll.2s="tick" data-test="host-game">
    <header class="flex h-[clamp(64px,9.8dvh,88px)] shrink-0 items-center justify-between gap-4 border-b-2 border-line bg-white {{ $gutter }}">
        <x-lamma.logo />

        @if ($state)
            <div class="flex items-center gap-3">
                <x-lamma.chip size="lg" class="border-2 border-line bg-white" data-test="progress-label">
                    @if ($revealed)
                        {{ __('Answer · Question :n', ['n' => $position]) }}
                    @else
                        {{ __('Question :n of :total', ['n' => $position, 'total' => $state->total]) }}
                    @endif
                </x-lamma.chip>
                <x-lamma.progress-dots :total="$state->total" :current="$position" />
            </div>

            <div class="flex items-center gap-3">
                <x-lamma.chip :tone="$tone" size="lg" :icon="$icon" class="hidden md:inline-flex" data-test="category">{{ $tr($state->category, 'name', $main) }}</x-lamma.chip>
                <x-lamma.room-code :code="$room->code" size="chip" data-test="room-code">{{ __('Room') }}</x-lamma.room-code>
                <button
                    type="button" wire:click="closeRoom" wire:confirm="{{ __('Close this room? Everyone in it will be disconnected.') }}"
                    class="min-h-11 px-2 text-[15px] font-semibold text-coral-700 hover:underline" data-test="close-room"
                >{{ __('Close room') }}</button>
            </div>
        @endif
    </header>

    @if ($state === null)
        <main class="flex grow flex-col items-center justify-center gap-6 px-5 text-center" data-test="get-ready" aria-live="polite">
            <x-lamma.dots class="[&>span]:size-5" />
            <h1 class="font-display text-[clamp(48px,7vw,96px)] font-extrabold leading-none">{{ __('Get ready…') }}</h1>
        </main>

    @elseif (! $revealed)
        {{-- host-4: the question, the four answers, who has answered. --}}
        <main
            wire:key="q-{{ $position }}-open"
            x-init="setTimeout(() => $wire.tick(), {{ $dueInMs + 150 }})"
            class="flex min-h-0 grow flex-col gap-[clamp(16px,4dvh,36px)] pb-[clamp(16px,4dvh,36px)] pt-[clamp(20px,4.4dvh,40px)] {{ $gutter }}"
        >
            <div class="flex items-center gap-10">
                <div class="flex min-w-0 grow flex-col gap-2.5 motion-safe:animate-fade-up">
                    <h1 lang="{{ $main }}" class="text-balance font-display text-[clamp(30px,4.2vw,60px)] font-extrabold leading-[1.1]" data-test="question-text">{{ $tr($state->question, 'text', $main) }}</h1>
                    @if ($both)
                        <p lang="ar" dir="rtl" class="text-balance text-end font-display text-[clamp(22px,2.7vw,38px)] font-bold leading-tight text-ink-muted" data-test="question-text-alt">{{ $tr($state->question, 'text', 'ar') }}</p>
                    @endif
                </div>
                <x-lamma.timer-ring :ends-at="$state->roomQuestion->ends_at" :seconds="$seconds" data-test="timer" />
            </div>

            <div class="grid grid-cols-2 gap-6" data-test="answers">
                @foreach ($state->options as $index => $option)
                    <x-lamma.answer :index="$index" :lang="$main" :text="$tr($option, 'text', $main)" :text-alt="$both ? $tr($option, 'text', 'ar') : null" data-option="{{ $option->id }}" />
                @endforeach
            </div>

            <div class="grow"></div>

            <footer class="flex items-center justify-between gap-6">
                <div class="flex min-w-0 items-center gap-4">
                    <div class="flex gap-2" data-test="answered-avatars">
                        @foreach ($state->players->take(14) as $player)
                            @php $done = $state->answerOf($player) !== null; @endphp
                            <x-lamma.avatar :name="$player->nickname" :color="$colors[$player->id]" :size="44" :checked="$done" class="{{ $done ? '' : 'opacity-40' }}" wire:key="who-{{ $player->id }}" />
                        @endforeach
                    </div>
                    <span class="font-display text-2xl font-bold" aria-live="polite" data-test="answered-count">{{ __(':answered of :total answered', ['answered' => $state->answered, 'total' => $state->expected]) }}</span>
                </div>

                <button type="button" wire:click="skipTimer({{ $position }})" class="flex min-h-11 items-center gap-2 text-base font-bold text-ink-muted hover:text-navy" data-test="skip-timer">
                    {{ __('Skip timer') }}
                    <x-lamma.icon name="arrow-right" :size="18" class="rtl:-scale-x-100" />
                </button>
            </footer>
        </main>

    @else
        {{-- host-5: the answer, who picked what, the scoreboard, and the pause before the next question. --}}
        @php $endMs = $nowMs + $dueInMs; @endphp
        <main
            wire:key="q-{{ $position }}-reveal"
            x-init="setTimeout(() => $wire.tick(), {{ $dueInMs + 150 }})"
            class="flex min-h-0 grow gap-9 pb-[clamp(16px,4dvh,40px)] pt-[clamp(16px,4dvh,36px)] {{ $gutter }}"
        >
            <div class="flex min-w-0 grow flex-col gap-[clamp(14px,3.1dvh,28px)]">
                <div class="flex flex-col gap-2">
                    <h1 lang="{{ $main }}" class="text-balance font-display text-[clamp(26px,3.2vw,46px)] font-extrabold leading-[1.1]" data-test="question-text">{{ $tr($state->question, 'text', $main) }}</h1>
                    @if ($both)
                        <p lang="ar" dir="rtl" class="text-balance text-end font-display text-[clamp(20px,2.1vw,30px)] font-bold leading-tight text-ink-muted">{{ $tr($state->question, 'text', 'ar') }}</p>
                    @endif
                </div>

                <div class="grid grid-cols-2 gap-x-[22px] gap-y-7 pt-3" data-test="answers">
                    @foreach ($state->options as $index => $option)
                        @php $correct = $option->id === $state->correctOptionId; $picked = $state->pickedBy($option->id); @endphp
                        <x-lamma.answer
                            :index="$index" :lang="$main" :text="$tr($option, 'text', $main)" :text-alt="$both ? $tr($option, 'text', 'ar') : null"
                            :state="$correct ? 'correct' : 'faded'" data-option="{{ $option->id }}" data-correct="{{ $correct ? 'true' : 'false' }}"
                        >
                            @if ($picked->isNotEmpty())
                                <x-slot:pickers>
                                    {{-- A crowd overlaps; past four it becomes a count, so the tile never runs out of room. --}}
                                    @foreach ($picked->take(4) as $player)
                                        <x-lamma.avatar :name="$player->nickname" :color="$colors[$player->id]" :size="36" />
                                    @endforeach
                                    @if ($picked->count() > 4)
                                        <span dir="ltr" class="flex size-9 shrink-0 items-center justify-center rounded-full border-3 border-navy bg-white text-[13px] font-extrabold text-navy">+{{ $picked->count() - 4 }}</span>
                                    @endif
                                </x-slot:pickers>
                            @endif
                        </x-lamma.answer>
                    @endforeach
                </div>

                <div class="grow"></div>

                <p class="flex items-center gap-3 font-display text-2xl font-bold" data-test="correct-count">
                    <span class="flex size-10 items-center justify-center rounded-full border-3 border-navy bg-teal"><x-lamma.icon name="check" :size="20" :stroke="3" /></span>
                    {{ __(':correct of :total got it right', ['correct' => $state->correctCount(), 'total' => $state->expected]) }}
                </p>
            </div>

            <x-lamma.scoreboard :ranking="$state->ranking" :previous="$state->previousPositions" :colors="$colors" class="w-[clamp(340px,29vw,420px)] shrink-0">
                <div class="flex flex-col gap-2.5" x-data="lammaTimer({{ $endMs }}, {{ $nowMs }}, {{ \App\Game\GameEngine::REVEAL_SECONDS }}, '')" data-test="next-countdown">
                    <div class="flex justify-between text-[15px] font-semibold text-ink-on-dark">
                        <span>{{ $state->isLast() ? __('Game ends in') : __('Next question in') }}</span>
                        <span dir="ltr" class="font-display text-xl font-extrabold text-sun"><span x-text="secs">{{ (int) ceil($dueInMs / 1000) }}</span> s</span>
                    </div>
                    <div class="h-2.5 rounded-full bg-navy-600" aria-hidden="true">
                        <div class="h-2.5 rounded-full bg-sun" style="width: {{ round(min(1, $dueInMs / (\App\Game\GameEngine::REVEAL_SECONDS * 1000)) * 100, 1) }}%" x-bind:style="{ width: `${frac * 100}%` }"></div>
                    </div>
                </div>
                <x-lamma.button icon="arrow-right" :on-dark="true" class="self-stretch" wire:click="next({{ $position }})" wire:loading.attr="disabled" wire:target="next" data-test="next-button">
                    {{ $state->isLast() ? __('Finish game') : __('Next question') }}
                </x-lamma.button>
            </x-lamma.scoreboard>
        </main>
    @endif
</div>
@endif
</div>
