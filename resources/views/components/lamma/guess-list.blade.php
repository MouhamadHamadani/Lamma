{{-- The ranked guesses of a closest-number question, nearest first, on the host's reveal: place, avatar and name, the guess, how far off it was (or "Exact"), and what it
     scored. The closest guess (a tie shares it) wears a sun row and a "Closest" badge. Only the first `limit` rows are drawn, with "+N more" under them: the scoreboard
     next to it lists everyone, and the screen never scrolls. guesses: GameState::$guesses. colors: player id => palette index. --}}
@use('App\Game\GuessNumber')
@use('App\Support\Isolate')
@props(['guesses' => [], 'colors' => [], 'limit' => 4])
<div {{ $attributes->class('flex flex-col gap-1.5') }} data-test="guess-list">
    @forelse (array_slice($guesses, 0, $limit) as $index => $guess)
        @php $player = $guess['player']; @endphp
        <div
            @class([
                'flex h-[clamp(34px,5.4dvh,48px)] items-center gap-3 rounded-chip border-2 px-3 motion-safe:animate-row-in motion-reduce:animate-fade-in',
                'border-navy bg-tint-sun' => $guess['closest'],
                'border-line bg-white' => ! $guess['closest'],
            ]) style="animation-delay: {{ $index * 60 }}ms" data-test="guess-row" data-closest="{{ $guess['closest'] ? 'true' : 'false' }}"
        >
            <span dir="ltr" class="w-5 shrink-0 text-center font-display text-lg font-extrabold text-ink-muted">{{ $index + 1 }}</span>
            <x-lamma.avatar :name="$player->nickname" :color="$colors[$player->id] ?? 0" :size="30" :photo="$player->avatarUrl()" />
            <bdi class="min-w-0 truncate text-[clamp(14px,1.3vw,18px)] font-bold">{{ $player->nickname }}</bdi>
            @if ($guess['closest'])
                <span class="inline-flex shrink-0 items-center gap-1 rounded-chip border-2 border-navy bg-sun px-2 text-xs font-extrabold"><x-lamma.icon name="bolt" :size="14" :stroke="2.4" />{{ __('Closest') }}</span>
            @endif
            <span class="grow"></span>
            <bdi dir="ltr" class="shrink-0 font-display text-xl font-extrabold">{{ GuessNumber::format((float) $guess['value']) }}</bdi>
            <span class="w-[5.5rem] shrink-0 text-end text-sm font-semibold text-ink-muted">{!! $guess['distance'] == 0 ? __('Exact') : __('Off by :n', ['n' => Isolate::ltr(GuessNumber::format((float) $guess['distance']))]) !!}</span>
            <span dir="ltr" @class(['w-14 shrink-0 text-end font-display text-lg font-extrabold', 'text-ink-subtle' => $guess['points'] === 0])>+{{ $guess['points'] }}</span>
        </div>
    @empty
        <p class="text-base font-semibold text-ink-muted" data-test="no-guesses">{{ __('Nobody guessed this one.') }}</p>
    @endforelse

    @if (count($guesses) > $limit)
        <p class="text-sm font-semibold text-ink-muted" data-test="more-guesses">{!! __('and :n more', ['n' => Isolate::ltr(count($guesses) - $limit)]) !!}</p>
    @endif
</div>
