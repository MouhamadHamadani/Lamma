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
    $correctOption = $state?->correctOptionId ? $state->options->firstWhere('id', $state->correctOptionId) : null;
    // Some row climbed this round: one "rank up" sound, a moment after the rows start sliding.
    $movedUp = $revealed && collect($state->ranking)->contains(fn (array $row) => ($row['rank_change'] ?? 0) > 0);
    $winner = $vote?->winner();
    $announce = match (true) {
        $state === null => '',
        $vote !== null && $vote->isOpen() => __('Vote started'),
        $vote !== null => __(':category won', ['category' => $winner?->getTranslation('name', $main, false) ?? '']),
        $revealed => __('The correct answer is :answer', ['answer' => $correctOption ? $tr($correctOption, 'text', $main) : '']),
        default => __('Question :n of :total', ['n' => $position, 'total' => $state->total]),
    };
@endphp
<div>
@if ($state?->finished)
    {{-- The game is over: the results screen (podium, Play again) takes the whole screen. --}}
    <livewire:host.host-results :room="$room" :key="'results-'.$room->id" />
@else
<div class="flex h-dvh flex-col overflow-hidden" wire:poll.2s="tick" data-test="host-game">
    {{-- The question number when it opens, the right answer at the reveal (HANDOFF section 8); the countdown speaks only at 10 s and 5 s. --}}
    <div class="sr-only" role="status" aria-live="polite" aria-atomic="true" data-test="announcer">{{ $announce }}</div>

    <header class="flex h-[clamp(64px,9.8dvh,88px)] shrink-0 items-center justify-between gap-4 border-b-2 border-line bg-white {{ $gutter }}">
        <x-lamma.logo />

        @if ($state)
            <div class="flex items-center gap-3">
                <x-lamma.chip size="lg" class="border-2 border-line bg-white" data-test="progress-label">
                    @if ($vote)
                        {{ __('Next category') }}
                    @elseif ($revealed)
                        {{ __('Answer · Question :n', ['n' => $position]) }}
                    @else
                        {{ __('Question :n of :total', ['n' => $position, 'total' => $state->total]) }}
                    @endif
                </x-lamma.chip>
                <x-lamma.progress-dots :total="$state->total" :current="$position" :every="$room->isVote() ? $room->vote_every : null" :voting="$vote !== null" />
            </div>
        @endif

        <div class="flex items-center gap-3">
            @if ($state && ! $vote)
                <x-lamma.chip :tone="$tone" size="lg" :icon="$icon" class="hidden md:inline-flex" data-test="category">{{ $state->category->fullName($main) }}</x-lamma.chip>
                <x-lamma.room-code :code="$room->code" size="chip" data-test="room-code">{{ __('Room') }}</x-lamma.room-code>
            @endif
            {{-- Above the 3 · 2 · 1 overlay (z-40), so it can be muted from the first second. --}}
            <x-lamma.sound-toggle class="relative z-50" />
            @if ($state)
                <button
                    type="button" wire:click="closeRoom" wire:confirm="{{ __('Close this room? Everyone in it will be disconnected.') }}"
                    class="min-h-11 px-2 text-[15px] font-semibold text-coral-700 hover:underline" data-test="close-room"
                >{{ __('Close room') }}</button>
            @endif
        </div>
    </header>

    @if ($state === null)
        {{-- Start game was pressed: 3 - 2 - 1 - Go! over the whole screen; the server opens question 1 when it ends (this screen asks at that moment). --}}
        <main
            class="flex grow flex-col items-center justify-center gap-6 px-5 text-center" data-test="get-ready" aria-live="polite"
            @if ($countdownEndsAt) x-init="setTimeout(() => $wire.tick(), {{ $dueInMs + 150 }})" @endif
        >
            <x-lamma.dots class="[&>span]:size-5" />
            <h1 class="font-display text-[clamp(48px,7vw,96px)] font-extrabold leading-none">{{ __('Get ready…') }}</h1>
            @if ($countdownEndsAt)
                <x-lamma.countdown :ends-at="$countdownEndsAt" />
            @endif
        </main>

    @elseif ($vote)
        {{-- Vote mode: the players choose the category of the next batch on their phones. Built from host-4 (the open question) and host-5 (the reveal):
             three upright answer tiles (colour + shape), live counts, who has voted, then the winner with who voted for what. --}}
        @php
            $round = $vote->round->round;
            $name = fn ($category, string $lang) => $category->getTranslation('name', $lang, false);
            $tied = $vote->tiedIndexes();
            $random = $vote->wasTie() || $vote->noVotes();
            $titleKey = match (true) {
                $vote->isOpen() => 'Choose the next category',
                $vote->wasTie() => 'Tie, picking randomly',
                $vote->noVotes() => 'Nobody voted, picking randomly',
                default => 'The next category is',
            };
        @endphp
        <main
            wire:key="v-{{ $round }}-{{ $vote->isOpen() ? 'open' : 'result' }}" data-test="vote" data-vote="{{ $vote->isOpen() ? 'open' : 'result' }}"
            {{-- One tick at the due moment, however many times a refresh re-runs this (Livewire does when the text changes): the poll is the fallback. --}}
            x-init="$store.sound.once('v{{ $round }}-{{ $vote->isOpen() ? 'close' : 'next' }}-tick', () => setTimeout(() => $wire.tick(), {{ $dueInMs + 150 }})); @if ($vote->isOpen()) $store.sound.once('v{{ $round }}-start', () => $store.sound.play('question-start')) @endif"
            class="flex min-h-0 grow flex-col gap-[clamp(16px,4dvh,36px)] pb-[clamp(16px,4dvh,36px)] pt-[clamp(20px,4.4dvh,40px)] {{ $gutter }}"
        >
            <div class="flex items-center gap-10">
                <div class="flex min-w-0 grow flex-col gap-2.5 motion-safe:animate-fade-up">
                    {{-- The keys are listed for the lang files: __('Choose the next category'), __('Tie, picking randomly'), __('Nobody voted, picking randomly'), __('The next category is') --}}
                    <h1 lang="{{ $main }}" class="text-balance font-display text-[clamp(30px,4.2vw,60px)] font-extrabold leading-[1.1]" data-test="vote-title">{{ __($titleKey, [], $main) }}</h1>
                    @if ($both)
                        <p lang="ar" dir="rtl" class="text-balance text-end font-display text-[clamp(22px,2.7vw,38px)] font-bold leading-tight text-ink-muted" data-test="vote-title-alt">{{ __($titleKey, [], 'ar') }}</p>
                    @endif
                </div>
                @if ($vote->isOpen())
                    <x-lamma.timer-ring :ends-at="$vote->round->ends_at" :seconds="\App\Game\CategoryVote::seconds()" :marks="[5]" data-test="timer" />
                @endif
            </div>

            @if ($vote->isOpen())
                <div class="grid grid-cols-3 gap-6 pt-3" data-test="vote-options">
                    @foreach ($vote->options as $index => $category)
                        <x-lamma.answer
                            size="vote" :index="$index" :lang="$main" :text="$name($category, $main)" :text-alt="$both ? $name($category, 'ar') : null"
                            :label="__('Category :letter: :text', ['letter' => chr(65 + $index), 'text' => $name($category, $main)])"
                            :count="$vote->counts[$category->id] ?? 0" data-category="{{ $category->id }}"
                        />
                    @endforeach
                </div>
            @else
                {{-- The result. A tie (or nobody voting) is a playful beat: the highlight hops across the tied tiles, then lands on the winner. Static for its
                     3 seconds, so wire:ignore keeps a refresh from replaying it. --}}
                <div
                    wire:ignore x-data="lammaVoteLanding({{ $random ? 'true' : 'false' }}, {{ Js::from($tied) }}, {{ (int) $vote->indexOf($winner?->id) }}, 'v{{ $round }}')"
                    x-bind:data-landing="landing" class="grid grid-cols-3 gap-6 pt-3 [&[data-landing]_[data-flourish]]:invisible" data-test="vote-options"
                >
                    @foreach ($vote->options as $index => $category)
                        @php
                            $won = $category->id === $winner?->id;
                            $voters = $vote->votersOf($category->id);
                            $isTied = in_array($index, $tied, true) ? 'true' : 'false';
                        @endphp
                        <x-lamma.answer
                            size="vote" :index="$index" :lang="$main" :text="$name($category, $main)" :text-alt="$both ? $name($category, 'ar') : null"
                            :label="__('Category :letter: :text', ['letter' => chr(65 + $index), 'text' => $name($category, $main)])"
                            :state="$won ? 'correct' : 'faded'" :badge="__('Winner')" :count="$vote->counts[$category->id] ?? 0"
                            data-category="{{ $category->id }}" data-index="{{ $index }}" data-winner="{{ $won ? 'true' : 'false' }}"
                            @class(['motion-safe:transition-[scale,opacity]', 'z-10 scale-105' => $won])
                            x-bind:class="landing ? { 'opacity-100!': {{ $isTied }}, 'scale-100!': hop !== {{ $index }}, 'scale-110!': hop === {{ $index }} } : {}"
                        >
                            @if ($voters->isNotEmpty())
                                <x-slot:pickers>
                                    @foreach ($voters->take(4) as $player)
                                        <x-lamma.avatar :name="$player->nickname" :color="$colors[$player->id] ?? 0" :size="36" :photo="$player->avatarUrl()" data-voter="{{ $player->id }}" />
                                    @endforeach
                                    @if ($voters->count() > 4)
                                        <span dir="ltr" class="flex size-9 shrink-0 items-center justify-center rounded-full border-3 border-navy bg-white text-[13px] font-extrabold text-navy">+{{ $voters->count() - 4 }}</span>
                                    @endif
                                </x-slot:pickers>
                            @endif
                        </x-lamma.answer>
                    @endforeach
                </div>
            @endif

            <div class="grow"></div>

            <footer class="flex items-center justify-between gap-6">
                <div class="flex min-w-0 items-center gap-4">
                    @if ($vote->isOpen())
                        <div class="flex gap-2" data-test="voted-avatars">
                            @foreach ($vote->players->take(14) as $player)
                                @php $done = $vote->voteOf($player) !== null; @endphp
                                <x-lamma.avatar :name="$player->nickname" :color="$colors[$player->id] ?? 0" :size="44" :photo="$player->avatarUrl()" :checked="$done" class="{{ $done ? '' : 'opacity-40' }}" wire:key="voter-{{ $player->id }}" />
                            @endforeach
                        </div>
                    @endif
                    <span class="font-display text-2xl font-bold" aria-live="polite" data-test="voted-count">{{ __(':voted of :total voted', ['voted' => $vote->voted, 'total' => $vote->expected]) }}</span>
                </div>

                @if ($vote->isOpen())
                    <button type="button" wire:click="skipVote({{ $round }})" class="flex min-h-11 items-center gap-2 text-base font-bold text-ink-muted hover:text-navy" data-test="skip-vote">
                        {{ __('Skip vote') }}
                        <x-lamma.icon name="arrow-right" :size="18" class="rtl:-scale-x-100" />
                    </button>
                @else
                    <p class="text-base font-bold text-ink-muted" data-test="vote-next">{{ __('Next question coming up…') }}</p>
                @endif
            </footer>
        </main>

    @elseif (! $revealed)
        {{-- host-4: the question, the four answers, who has answered. --}}
        <main
            wire:key="q-{{ $position }}-open"
            x-init="setTimeout(() => $wire.tick(), {{ $dueInMs + 150 }}); $store.sound.once('q{{ $position }}-start', () => $store.sound.play('question-start'))"
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
                            <x-lamma.avatar :name="$player->nickname" :color="$colors[$player->id]" :size="44" :photo="$player->avatarUrl()" :checked="$done" class="{{ $done ? '' : 'opacity-40' }}" wire:key="who-{{ $player->id }}" />
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
            x-init="setTimeout(() => $wire.tick(), {{ $dueInMs + 150 }}); $store.sound.once('q{{ $position }}-reveal', () => { $store.sound.play('reveal'); @if ($movedUp) setTimeout(() => $store.sound.play('rank-up'), 700); @endif })"
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
                                        <x-lamma.avatar :name="$player->nickname" :color="$colors[$player->id]" :size="36" :photo="$player->avatarUrl()" />
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
                <div class="flex flex-col gap-2.5" wire:ignore x-data="lammaTimer({{ $endMs }}, {{ $nowMs }}, {{ \App\Game\GameEngine::REVEAL_SECONDS }}, '')" data-test="next-countdown">
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
