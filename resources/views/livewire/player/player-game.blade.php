{{-- References: docs/design/screens/player-3-question.html (+ -ar), player-4-answered.html, player-5-correct.html. Designed at 390x844, a phone-width
     column on bigger screens. Everything is the player's own language. State comes from the database (GameView) on every render, refreshed by the
     broadcast events and a 4 s poll, so a reconnect or a reload lands on the right screen. Nothing here says which option is correct until the reveal. --}}
@use('App\Game\Scoreboard')
@use('App\Support\Isolate')
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
        'correct' => $streak >= 3 ? __('Correct, plus :points points, :count in a row', ['points' => $gained, 'count' => $streak]) : __('Correct, plus :points points', ['points' => $gained]),
        'wrong' => __('Not quite, the answer was :answer', ['answer' => $correctOption ? $tr($correctOption, 'text') : '']),
        'timeout' => __("Time's up, the answer was :answer", ['answer' => $correctOption ? $tr($correctOption, 'text') : '']),
        'vote' => $vote->wasExtended() ? __('Vote extended') : __('Vote started'),
        'voted' => __('Vote locked in'),
        'vote-result' => __(':category won', ['category' => $vote?->winner()?->getTranslation('name', $lang, false) ?? '']),
        default => '',
    };
    if ($state?->doubled && in_array($phase, ['question', 'correct'], true)) {
        $announce .= '. '.__('Double points');
    }
    $seconds = $room->settings->secondsPerQuestion;
@endphp
<div class="relative min-h-dvh" @if ($phase !== 'over') wire:poll.4s @endif data-test="player-game" data-phase="{{ $phase }}">
@if ($phase === 'over')
    {{-- The game is over: the results screen (rank, leaderboard, save your score, follow Play again) takes over. --}}
    <livewire:player.player-results :room="$room" :key="'results-'.$room->id" />
@else
    @if ($phase === 'correct')
        {{-- The teal screen wipes up from the bottom (a fade under reduced motion). --}}
        <div aria-hidden="true" class="pointer-events-none absolute inset-0 origin-bottom bg-teal motion-safe:animate-wipe-in motion-reduce:animate-fade-in"></div>
        <x-lamma.confetti :count="5" />
    @endif

    <div class="relative mx-auto flex min-h-dvh w-full max-w-md flex-col px-5 pb-[max(1.75rem,env(safe-area-inset-bottom))]">
        {{-- z-50: above the 3 · 2 · 1 overlay. In a game the sound and vibration switches take the logo's place: the question number and the score need the room. --}}
        <header class="relative z-50 flex h-16 shrink-0 items-center justify-between">
            <div class="flex items-center gap-2">
                @unless ($state)
                    <x-lamma.logo size="sm" class="me-1 max-[379px]:[&>span]:hidden" />
                @endunless
                <x-lamma.sound-toggle :phone="true" />
                <x-lamma.haptics-toggle />
            </div>
            @if ($state && ! $state->finished)
                <div class="flex items-center gap-2">
                    <x-lamma.chip size="sm" class="border-2 border-line bg-white"><bdi dir="ltr" data-test="question-number">{{ __('Q :n / :total', ['n' => $position, 'total' => $state->total]) }}</bdi></x-lamma.chip>
                    @unless (in_array($phase, ['correct', 'wrong', 'timeout'], true))
                        <x-lamma.chip tone="sun" size="sm"><span data-test="score-chip">{!! __(':points pts', ['points' => Isolate::ltr($me->score)]) !!}</span></x-lamma.chip>
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
                @if ($countdownEndsAt)
                    <x-lamma.countdown :ends-at="$countdownEndsAt" />
                @endif
            </main>

        @elseif (in_array($phase, ['vote', 'voted'], true))
            {{-- Vote mode (built from player-3 and player-4): the category of the next questions. One tap locks the vote; then the chosen tile, big and
                 tilted, while the others vote. Nothing about anyone else's vote is drawn here. --}}
            @php $round = $vote->round->round; @endphp
            <main wire:key="pv-{{ $round }}-{{ $phase }}" class="flex grow flex-col gap-4 pb-6 pt-2" data-test="vote" data-vote="{{ $phase }}">
                {{-- The key changes with the host's "+10 s", so the timer (wire:ignore, on its own clock) is redrawn from the new deadline. --}}
                <x-lamma.timer-bar wire:key="vote-timer-{{ $round }}-{{ $vote->seconds() }}" :ends-at="$vote->round->ends_at" :seconds="$vote->seconds()" :marks="[5]" data-test="timer" />

                @if ($phase === 'vote')
                    <div class="flex flex-col gap-1 rounded-tile border-2 border-line bg-white px-5 py-[18px] motion-safe:animate-fade-up">
                        <span class="flex items-center gap-1.5 text-[13px] font-bold text-ink-muted"><x-lamma.icon name="chart" :size="16" />{{ __('Vote') }}</span>
                        <h1 class="text-balance font-display text-[25px] font-extrabold leading-tight" data-test="vote-title">{{ __('Choose the next category') }}</h1>
                    </div>

                    <div class="flex flex-col gap-3" data-test="vote-options">
                        @foreach ($vote->options as $index => $category)
                            @php $label = $category->getTranslation('name', $lang, false); @endphp
                            <x-lamma.answer
                                size="phone" :index="$index" :lang="$lang" :text="$label"
                                :label="__('Category :letter: :text', ['letter' => chr(65 + $index), 'text' => $label])"
                                wire:click="vote({{ $category->id }})" wire:loading.attr="disabled" wire:target="vote"
                                x-on:click="$store.sound.play('lock-in'); $store.sound.buzz('tap')"
                                class="motion-safe:animate-slide-in motion-reduce:animate-fade-in" style="animation-delay: {{ $index * 60 }}ms" data-category="{{ $category->id }}"
                            />
                        @endforeach
                    </div>

                    <div class="grow"></div>
                    <p class="text-center text-sm text-ink-subtle">{{ __("Tap one. You can't change it after.") }}</p>
                @else
                    @php $mine = $vote->options->firstWhere('id', $myVote); @endphp
                    <div class="flex grow flex-col items-center justify-center gap-[22px] text-center">
                        <span class="text-sm font-bold text-coral-700">{{ __('Vote locked in') }}</span>
                        <x-lamma.answer
                            size="phone" state="locked" :index="(int) $vote->indexOf($myVote)" :lang="$lang" :text="$mine?->getTranslation('name', $lang, false) ?? ''"
                            :label="__('Category :letter: :text', ['letter' => chr(65 + (int) $vote->indexOf($myVote)), 'text' => $mine?->getTranslation('name', $lang, false) ?? ''])"
                            class="motion-safe:animate-settle motion-reduce:animate-fade-in" data-test="locked-vote"
                        />
                        <span aria-hidden="true" class="flex gap-2">
                            @foreach (['bg-coral', 'bg-sun', 'bg-teal'] as $n => $color)
                                <span class="size-3 rounded-full {{ $color }} motion-safe:animate-dots" style="animation-delay: {{ $n * 150 }}ms"></span>
                            @endforeach
                        </span>
                        <div class="flex flex-col gap-1.5">
                            <h1 class="font-display text-[28px] font-extrabold">{{ __('Waiting for the others…') }}</h1>
                            <p class="text-base text-ink-muted" aria-live="polite" data-test="voted-count">{{ __(':voted of :total voted', ['voted' => $vote->voted, 'total' => $vote->expected]) }}</p>
                        </div>
                    </div>

                    <p class="text-center text-sm text-ink-subtle">{{ __('The next category shows when time is up or everyone has voted.') }}</p>
                @endif
            </main>

        @elseif ($phase === 'vote-result')
            {{-- Vote mode: the winning category, and whether this player's pick won. No score, no rank: the next question follows in a moment. --}}
            @php
                $round = $vote->round->round;
                $winner = $vote->winner();
                $winnerName = $winner?->getTranslation('name', $lang, false) ?? '';
                $winnerIndex = (int) $vote->indexOf($winner?->id);
            @endphp
            <main
                wire:key="pv-{{ $round }}-result" class="relative flex grow flex-col items-center gap-[18px] pb-7 pt-8 text-center" data-test="vote-result"
                x-init="$store.sound.once('pv{{ $round }}-result', () => $store.sound.play('reveal'))"
            >
                @if ($myVote === null)
                    <span class="rounded-chip bg-tint-sun px-3.5 py-1.5 text-sm font-bold" data-test="no-vote">{{ __("You didn't vote") }}</span>
                @endif
                <span class="text-sm font-bold text-coral-700" data-test="vote-outcome">
                    {{ $vote->wasTie() ? __('Tie, picking randomly') : ($vote->noVotes() ? __('Nobody voted, picking randomly') : __('The next category is')) }}
                </span>
                <x-lamma.answer
                    size="phone" state="locked" :index="$winnerIndex" :lang="$lang" :text="$winnerName"
                    :label="__('Category :letter: :text', ['letter' => chr(65 + $winnerIndex), 'text' => $winnerName])"
                    class="motion-safe:animate-settle motion-reduce:animate-fade-in" data-test="winner"
                />
                @if ($myVote !== null && $myVote === $winner?->id)
                    <p class="font-display text-[26px] font-extrabold motion-safe:animate-chip-pop" data-test="pick-won">{{ __('Your pick won!') }}</p>
                @endif

                <div class="grow"></div>
                <p class="text-[15px] font-bold">{{ __('Next question coming up…') }}</p>
            </main>

        @elseif ($phase === 'question')
            {{-- player-3 --}}
            <main wire:key="p-{{ $position }}-question" class="flex grow flex-col gap-4 pb-6 pt-2">
                <x-lamma.timer-bar :ends-at="$state->roomQuestion->ends_at" :seconds="$seconds" data-test="timer" />

                <div class="flex flex-col gap-2 rounded-tile border-2 border-line bg-white px-5 py-[18px] motion-safe:animate-fade-up">
                    <div class="flex items-center justify-between gap-2">
                        <span class="flex items-center gap-1.5 text-[13px] font-bold text-ink-muted"><x-lamma.icon :name="$icon" :size="16" />{{ $state->category->fullName($lang) }}</span>
                        @if ($state->doubled)
                            <x-lamma.double-chip />
                        @endif
                    </div>
                    <h1 lang="{{ $lang }}" class="text-balance font-display text-[25px] font-extrabold leading-tight" data-test="question-text">{{ $tr($state->question, 'text') }}</h1>
                </div>

                <div class="flex flex-col gap-3" data-test="answers">
                    @foreach ($state->options as $index => $option)
                        <x-lamma.answer
                            size="phone" :index="$index" :lang="$lang" :text="$tr($option, 'text')"
                            wire:click="answer({{ $option->id }})" wire:loading.attr="disabled" wire:target="answer"
                            x-on:click="$store.sound.play('lock-in'); $store.sound.buzz('tap')"
                            class="motion-safe:animate-slide-in motion-reduce:animate-fade-in" style="animation-delay: {{ $index * 60 }}ms" data-option="{{ $option->id }}"
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
                    <x-lamma.answer size="phone" state="locked" :index="(int) $state->indexOf($answer->question_option_id)" :lang="$lang" :text="$chosen ? $tr($chosen, 'text') : ''" class="motion-safe:animate-settle motion-reduce:animate-fade-in" data-test="locked-answer" />
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
            {{-- One sound, one vibration and (when right) one burst of confetti per answer, even if a refresh runs this x-init again. A right answer is a long buzz,
                 a wrong one a short soft one (never harsh); the streak chime follows the "correct" one. --}}
            <main
                wire:key="p-{{ $position }}-reveal" class="relative flex grow flex-col items-center gap-[18px] pb-7 pt-5 text-center" data-test="reveal-{{ $phase }}"
                x-init="$store.sound.once('p{{ $position }}-result', () => { $store.sound.play('{{ $phase === 'correct' ? 'correct' : 'wrong' }}'); $store.sound.buzz('{{ $phase === 'correct' ? 'correct' : 'wrong' }}'); @if ($phase === 'correct') setTimeout(() => lammaConfetti.burst($el.querySelector('[data-test=points-gained]'), 22), 350); @if ($streak >= 3) setTimeout(() => $store.sound.play('streak'), 750); @endif @endif })"
            >
                <span @class([
                    'flex size-32 items-center justify-center rounded-full border-3 border-navy shadow-sticker-lg [@media(max-height:699px)]:size-24',
                    'motion-safe:animate-reveal' => $phase === 'correct',
                    'motion-safe:animate-shake' => $phase !== 'correct',
                    'bg-cream' => $phase === 'correct',
                    'bg-tint-coral' => $phase === 'wrong',
                    'bg-tint-sun' => $phase === 'timeout',
                ])>
                    @if ($phase === 'correct')
                        {{-- The check draws itself, stroke by stroke (pathLength 1 lets one dash cover the whole stroke). --}}
                        <svg viewBox="0 0 24 24" width="64" height="64" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" data-test="check-draw">
                            <path pathLength="1" stroke-dasharray="1" d="M5 12.5l4.5 4.5L19 7.5" class="motion-safe:animate-draw" />
                        </svg>
                    @else
                        <x-lamma.icon :name="['wrong' => 'x', 'timeout' => 'clock'][$phase]" :size="64" :stroke="3" />
                    @endif
                </span>

                <h1 class="font-display text-[56px] font-extrabold leading-none motion-safe:animate-fade-up" data-test="result-title">
                    {{ ['correct' => __('Correct!'), 'wrong' => __('Not quite!'), 'timeout' => __("Time's up!")][$phase] }}
                </h1>

                <div class="flex flex-wrap items-center justify-center gap-3">
                    <span
                        dir="ltr" x-data="lammaCountUp({{ $gained }}, 400, {{ $gained > 0 ? 'true' : 'false' }})" data-test="points-gained"
                        @class([
                            'inline-flex h-16 -rotate-3 items-center rounded-[18px] border-3 border-navy px-7 font-display text-[40px] font-extrabold shadow-sticker motion-safe:animate-chip-pop',
                            'bg-sun' => $phase === 'correct',
                            'bg-white' => $phase !== 'correct',
                        ])
                    >+<span x-text="n">{{ $gained }}</span></span>
                    @if ($phase === 'correct' && $streak >= 3)
                        {{-- Three or more right in a row. Display only: a streak scores nothing. --}}
                        <x-lamma.streak-chip :count="$streak" class="motion-safe:animate-chip-pop" style="animation-delay: 300ms" />
                    @endif
                </div>

                {{-- How the +N was made up. An answer from before the speed bonus (a game running across the deploy) has no breakdown. --}}
                @if ($phase === 'correct' && $answer->speed_bonus !== null)
                    <div class="flex flex-col items-center gap-1.5 text-[15px] font-bold" data-test="points-breakdown">
                        <p>{!! __(':base + :bonus speed', ['base' => Isolate::ltr($answer->base_points), 'bonus' => Isolate::ltr($answer->speed_bonus)]) !!}</p>
                        @if ($answer->multiplier > 1)
                            <p class="flex items-center gap-2"><x-lamma.double-chip />{{ __('final question') }}</p>
                        @endif
                    </div>
                @endif

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
