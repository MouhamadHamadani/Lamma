{{-- References: docs/design/screens/player-3-question.html (+ -ar), player-4-answered.html, player-5-correct.html. Designed at 390x844, a phone-width
     column on bigger screens. Everything is the player's own language. State comes from the database (GameView) on every render, refreshed by the
     broadcast events and a 4 s poll, so a reconnect or a reload lands on the right screen. Nothing here says which option is correct until the reveal. --}}
@use('App\Game\Scoreboard')
@php
    $tr = fn ($model, string $attribute) => $model->getTranslation($attribute, $lang, false);
    $position = $state?->position();
    $chosen = $answer ? $state->options->firstWhere('id', $answer->question_option_id) : null;
    $correctOption = $state?->correctOptionId ? $state->options->firstWhere('id', $state->correctOptionId) : null;
    $gained = $answer?->points ?? 0;
    $rankHtml = $row ? '<bdi dir="ltr">'.e(Scoreboard::ordinal($row['rank'])).'</bdi>' : '';
    $pointsHtml = '<bdi dir="ltr">'.e((string) ($row['total'] ?? $me->score)).'</bdi>';
    $announce = match ($phase) {
        'question' => __('Question :n of :total', ['n' => $position, 'total' => $state?->total]),
        'answered' => __('Answer locked in'),
        'correct' => __('Correct! You got :points points.', ['points' => $gained]),
        'wrong' => __('Not quite. The correct answer is :answer.', ['answer' => $correctOption ? $tr($correctOption, 'text') : '']),
        'timeout' => __("Time's up. The correct answer is :answer.", ['answer' => $correctOption ? $tr($correctOption, 'text') : '']),
        default => '',
    };
    $seconds = $room->settings->secondsPerQuestion;
@endphp
<div @class(['relative min-h-dvh', 'bg-teal' => $phase === 'correct']) @if ($phase !== 'over') wire:poll.4s @endif data-test="player-game" data-phase="{{ $phase }}">
@if ($phase === 'over')
    {{-- The game is over: the results screen (rank, leaderboard, save your score, follow Play again) takes over. --}}
    <livewire:player.player-results :room="$room" :key="'results-'.$room->id" />
@else
    @if ($phase === 'correct')
        <x-lamma.confetti :count="5" />
    @endif

    <div class="relative mx-auto flex min-h-dvh w-full max-w-md flex-col px-5 pb-[max(1.75rem,env(safe-area-inset-bottom))]">
        <header class="flex h-16 shrink-0 items-center justify-between">
            <x-lamma.logo size="sm" />
            @if ($state && ! $state->finished)
                <div class="flex items-center gap-2">
                    <x-lamma.chip size="sm" class="border-2 border-line bg-white"><bdi dir="ltr" data-test="question-number">{{ __('Q :n / :total', ['n' => $position, 'total' => $state->total]) }}</bdi></x-lamma.chip>
                    @unless (in_array($phase, ['correct', 'wrong', 'timeout'], true))
                        <x-lamma.chip tone="sun" size="sm"><bdi dir="ltr" data-test="score-chip">{{ __(':points pts', ['points' => $me->score]) }}</bdi></x-lamma.chip>
                    @endunless
                </div>
            @endif
        </header>

        {{-- Read out on every change (VoiceOver / TalkBack); the countdown speaks only at 10 s and 5 s. --}}
        <div class="sr-only" role="status" aria-live="polite" aria-atomic="true" data-test="announcer">{{ $announce }}</div>

        @if ($phase === 'waiting')
            <main class="flex grow flex-col items-center justify-center gap-6 text-center" data-test="get-ready" aria-live="polite">
                <x-lamma.dots class="[&>span]:size-4" />
                <h1 class="font-display text-[44px] font-extrabold leading-none">{{ __('Get ready…') }}</h1>
            </main>

        @elseif ($phase === 'question')
            {{-- player-3 --}}
            <main wire:key="p-{{ $position }}-question" class="flex grow flex-col gap-4 pb-6 pt-2">
                <x-lamma.timer-bar :ends-at="$state->roomQuestion->ends_at" :seconds="$seconds" data-test="timer" />

                <div class="flex flex-col gap-2 rounded-tile border-2 border-line bg-white px-5 py-[18px] motion-safe:animate-fade-up">
                    <span class="flex items-center gap-1.5 text-[13px] font-bold text-ink-muted"><x-lamma.icon :name="$icon" :size="16" />{{ $tr($state->category, 'name') }}</span>
                    <h1 lang="{{ $lang }}" class="text-balance font-display text-[25px] font-extrabold leading-tight" data-test="question-text">{{ $tr($state->question, 'text') }}</h1>
                </div>

                <div class="flex flex-col gap-3" data-test="answers">
                    @foreach ($state->options as $index => $option)
                        <x-lamma.answer
                            size="phone" :index="$index" :lang="$lang" :text="$tr($option, 'text')"
                            wire:click="answer({{ $option->id }})" wire:loading.attr="disabled" wire:target="answer"
                            class="motion-safe:animate-pop-in" style="animation-delay: {{ $index * 60 }}ms" data-option="{{ $option->id }}"
                        />
                    @endforeach
                </div>

                <div class="grow"></div>
                <p class="text-center text-sm text-ink-subtle">{{ __("Tap one answer. You can't change it after.") }}</p>
            </main>

        @elseif ($phase === 'answered')
            {{-- player-4 --}}
            <main wire:key="p-{{ $position }}-answered" class="flex grow flex-col gap-5 pb-7 pt-2">
                <x-lamma.timer-bar :ends-at="$state->roomQuestion->ends_at" :seconds="$seconds" data-test="timer" />

                <div class="flex grow flex-col items-center justify-center gap-[22px] text-center">
                    <span class="text-sm font-bold text-coral-700">{{ __('Answer locked in') }}</span>
                    <x-lamma.answer size="phone" state="locked" :index="(int) $state->indexOf($answer->question_option_id)" :lang="$lang" :text="$chosen ? $tr($chosen, 'text') : ''" class="motion-safe:animate-pop-in" data-test="locked-answer" />
                    <span aria-hidden="true" class="flex gap-2">
                        @foreach (['bg-coral', 'bg-sun', 'bg-teal'] as $n => $color)
                            <span class="size-3 rounded-full {{ $color }} motion-safe:animate-dots" style="animation-delay: {{ $n * 150 }}ms"></span>
                        @endforeach
                    </span>
                    <div class="flex flex-col gap-1.5">
                        <h1 class="font-display text-[28px] font-extrabold">{{ __('Waiting for the others…') }}</h1>
                        <p class="text-base text-ink-muted" aria-live="polite" data-test="answered-count">{{ __(':answered of :total answered', ['answered' => $state->answered, 'total' => $state->expected]) }}</p>
                    </div>
                </div>

                <p class="text-center text-sm text-ink-subtle">{{ __('The answer shows when time is up or everyone has answered.') }}</p>
            </main>

        @elseif (in_array($phase, ['correct', 'wrong', 'timeout'], true))
            {{-- player-5: teal when right; cream for "not quite" and "time's up" --}}
            <main wire:key="p-{{ $position }}-reveal" class="relative flex grow flex-col items-center gap-[18px] pb-7 pt-5 text-center" data-test="reveal-{{ $phase }}">
                <span @class([
                    'flex size-32 items-center justify-center rounded-full border-3 border-navy shadow-sticker-lg motion-safe:animate-reveal',
                    'bg-cream' => $phase === 'correct',
                    'bg-tint-coral' => $phase === 'wrong',
                    'bg-tint-sun' => $phase === 'timeout',
                ])>
                    <x-lamma.icon :name="['correct' => 'check', 'wrong' => 'x', 'timeout' => 'clock'][$phase]" :size="64" :stroke="3" />
                </span>

                <h1 class="font-display text-[56px] font-extrabold leading-none motion-safe:animate-fade-up" data-test="result-title">
                    {{ ['correct' => __('Correct!'), 'wrong' => __('Not quite!'), 'timeout' => __("Time's up!")][$phase] }}
                </h1>

                <span
                    dir="ltr" x-data="lammaCountUp({{ $gained }})" data-test="points-gained"
                    @class([
                        'inline-flex h-16 -rotate-3 items-center rounded-[18px] border-3 border-navy px-7 font-display text-[40px] font-extrabold shadow-sticker motion-safe:animate-chip-pop',
                        'bg-sun' => $phase === 'correct',
                        'bg-white' => $phase !== 'correct',
                    ])
                >+<span x-text="n">{{ $gained }}</span></span>

                @if ($row)
                    <p class="font-display text-[22px] font-bold" data-test="rank-line">{!! __("You're :rank · :points pts", ['rank' => $rankHtml, 'points' => $pointsHtml]) !!}</p>
                @endif

                <div class="grow"></div>

                @if ($correctOption)
                    @php $correctIndex = (int) $state->indexOf($correctOption->id); @endphp
                    <div class="flex w-full items-center gap-3.5 rounded-tile border-2 border-line bg-cream px-[18px] py-4 text-start" data-test="correct-answer">
                        <span class="flex size-11 shrink-0 items-center justify-center rounded-xl border-3 border-navy {{ ['bg-coral', 'bg-teal', 'bg-sun', 'bg-navy text-cream'][$correctIndex % 4] }}">
                            <x-lamma.shape :index="$correctIndex" :size="22" />
                        </span>
                        <span class="flex flex-col">
                            <span class="text-[13px] font-bold text-ink-subtle">{{ __('Correct answer') }}</span>
                            <span lang="{{ $lang }}" class="font-display text-[22px] font-extrabold leading-tight">{{ $tr($correctOption, 'text') }}</span>
                        </span>
                    </div>
                @endif

                <p class="text-[15px] font-bold">{{ $state->isLast() ? __('Final results coming up…') : __('Next question coming up…') }}</p>
            </main>

        @else
            <main class="flex grow flex-col items-center justify-center gap-6 text-center" data-test="closed">
                <h1 class="font-display text-[34px] font-extrabold leading-tight">{{ __('This room has been closed.') }}</h1>
                <x-lamma.button :href="route('home')" variant="outline">{{ __('Back to home') }}</x-lamma.button>
            </main>
        @endif
    </div>
@endif
</div>
