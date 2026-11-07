{{-- The reveal of a closest-number question on the host screen: everyone's guesses as avatars standing on a number line, the right number marked underneath
     with a check. Numbers run left to right in both languages, so the line is dir="ltr" and a pin's place is an inline-start percentage. A pin whose neighbour is
     close stands one step higher, so they do not sit on top of each other; the closest guess has a sun ring. The line spans the guesses and the answer with a little
     room at each end (and works when they are all the same number). The pins and the marker drop in one after another; a plain fade under reduced motion.
     guesses: GameState::$guesses (player, value, distance, closest). colors: player id => palette index. --}}
@use('App\Game\GuessNumber')
@props(['correct', 'guesses' => [], 'colors' => []])
@php
    $values = [(float) $correct, ...array_map(fn (array $guess) => (float) $guess['value'], $guesses)];
    $low = min($values);
    $high = max($values);
    $pad = $high - $low > 0 ? ($high - $low) * 0.14 : max(1.0, abs((float) $correct) * 0.1);
    $at = fn (float $value): float => round(($value - ($low - $pad)) / (($high + $pad) - ($low - $pad)) * 100, 2);

    // Left to right; a pin within 7% of the one before it goes up a step, then back down.
    $pins = [];
    $lane = 0;
    $before = null;
    foreach (collect($guesses)->sortBy('value')->values() as $guess) {
        $x = $at((float) $guess['value']);
        $lane = $before !== null && $x - $before < 7 ? ($lane + 1) % 2 : 0;
        $pins[] = [...$guess, 'x' => $x, 'lane' => $lane];
        $before = $x;
    }
@endphp
<div dir="ltr" {{ $attributes->class('relative h-[clamp(128px,24dvh,210px)] w-full') }} role="group" aria-label="{{ __('The guesses on a number line') }}" data-test="number-line">
    <div class="absolute inset-x-0 top-[60%] h-1.5 -translate-y-1/2 rounded-full bg-navy" aria-hidden="true"></div>

    <div class="absolute top-[60%] flex -translate-x-1/2 flex-col items-center" style="inset-inline-start: {{ $at((float) $correct) }}%" data-test="correct-marker">
        <span class="h-4 w-1.5 rounded-full bg-navy" aria-hidden="true"></span>
        <span class="inline-flex h-10 items-center gap-1.5 rounded-chip border-3 border-navy bg-teal px-3.5 font-display text-xl font-extrabold shadow-sticker-sm motion-safe:animate-drop-in motion-reduce:animate-fade-in" style="animation-delay: {{ (count($pins) + 1) * 90 }}ms">
            <x-lamma.icon name="check" :size="18" :stroke="3" />
            {{ GuessNumber::format((float) $correct) }}
        </span>
    </div>

    @foreach ($pins as $index => $pin)
        @php $player = $pin['player']; @endphp
        <div class="absolute bottom-[40%] flex -translate-x-1/2 flex-col items-center" style="inset-inline-start: {{ $pin['x'] }}%" data-test="pin" data-closest="{{ $pin['closest'] ? 'true' : 'false' }}">
            <span class="flex flex-col items-center motion-safe:animate-drop-in motion-reduce:animate-fade-in" style="animation-delay: {{ $index * 90 }}ms">
                <x-lamma.avatar :name="$player->nickname" :color="$colors[$player->id] ?? 0" :size="36" :photo="$player->avatarUrl()" @class(['rounded-full ring-4 ring-sun' => $pin['closest']]) />
                <span class="w-1 rounded-full bg-navy" style="height: {{ 8 + $pin['lane'] * 42 }}px" aria-hidden="true"></span>
            </span>
        </div>
    @endforeach

    <ul class="sr-only">
        @foreach ($pins as $pin)
            <li><bdi>{{ $pin['player']->nickname }}</bdi>: <bdi dir="ltr">{{ GuessNumber::format((float) $pin['value']) }}</bdi></li>
        @endforeach
    </ul>
</div>
