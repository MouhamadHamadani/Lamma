{{-- First letter of the name in a navy-ringed circle. color: player index (cycles the palette) or a literal bg-* class. checked: teal "answered" badge. --}}
@props(['name', 'color' => 0, 'size' => 48, 'checked' => false])
@php
    $palette = ['bg-tint-coral', 'bg-teal', 'bg-sun', 'bg-coral', 'bg-tint-teal', 'bg-tint-sun'];
    $bg = is_int($color) ? $palette[$color % count($palette)] : $color;
@endphp
<span {{ $attributes->class('relative inline-flex shrink-0') }}>
    <span
        aria-hidden="true"
        class="{{ $bg }} flex items-center justify-center rounded-full border-3 border-navy font-display font-extrabold text-navy"
        style="width: {{ $size }}px; height: {{ $size }}px; font-size: {{ round($size * .42) }}px"
    >{{ mb_strtoupper(mb_substr($name, 0, 1)) }}</span>
    @if ($checked)
        <span aria-hidden="true" class="absolute -bottom-1 -end-1 flex size-[22px] motion-safe:animate-chip-pop items-center justify-center rounded-full border-2 border-navy bg-teal text-navy">
            <x-lamma.icon name="check" :size="12" :stroke="3.5" />
        </span>
    @endif
</span>
