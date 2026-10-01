{{-- Decorative scatter (aria-hidden). Fill a `relative` parent; positions use inset-inline-start, so RTL mirrors them and the tilts. --}}
@props(['count' => 12])
@php
    // [shape, colour, px, top %, inline-start %, tilt deg]
    $pieces = [
        [0, 'text-coral', 30, 14, 44, -12], [1, 'text-teal', 18, 6, 30, 0], [2, 'text-sun', 24, 10, 86, 18],
        [2, 'text-teal', 16, 22, 36, -20], [0, 'text-sun', 22, 48, 94, 30], [1, 'text-coral', 14, 70, 90, 0],
        [3, 'text-sun', 18, 82, 6, 12], [0, 'text-teal', 26, 62, 3, -16], [2, 'text-coral', 20, 88, 52, 24],
        [1, 'text-sun', 12, 34, 4, 0], [3, 'text-coral', 16, 92, 82, -8], [1, 'text-teal', 14, 44, 60, 0],
    ];
@endphp
<div aria-hidden="true" {{ $attributes->class('pointer-events-none absolute inset-0 overflow-hidden') }}>
    @foreach (array_slice($pieces, 0, $count) as [$shape, $color, $size, $top, $start, $tilt])
        <x-lamma.shape
            :index="$shape" :size="$size"
            class="absolute {{ $color }} rotate-[var(--r)] rtl:rotate-[calc(var(--r)*-1)]"
            style="--r: {{ $tilt }}deg; top: {{ $top }}%; inset-inline-start: {{ $start }}%"
        />
    @endforeach
</div>
