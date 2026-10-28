{{-- First letter of the name in a navy-ringed circle, or the player's selfie. color: player index (cycles the palette) or a literal bg-* class.
     checked: teal "answered" badge. photo: the selfie's URL (App\Game\PlayerPhoto): a round image inside the same ring, alt = the name. The letter stays
     underneath, so a photo that is missing or fails to load (onerror removes the image) simply shows the initial. lazy: loading="lazy", except where the
     avatar is the first thing on screen (the lobby). --}}
@props(['name', 'color' => 0, 'size' => 48, 'checked' => false, 'photo' => null, 'lazy' => true])
@php
    $palette = ['bg-tint-coral', 'bg-teal', 'bg-sun', 'bg-coral', 'bg-tint-teal', 'bg-tint-sun'];
    $bg = is_int($color) ? $palette[$color % count($palette)] : $color;
@endphp
<span {{ $attributes->class('relative inline-flex shrink-0') }}>
    <span
        class="{{ $bg }} relative flex items-center justify-center overflow-hidden rounded-full border-3 border-navy font-display font-extrabold text-navy"
        style="width: {{ $size }}px; height: {{ $size }}px; font-size: {{ round($size * .42) }}px"
    >
        <span aria-hidden="true">{{ mb_strtoupper(mb_substr($name, 0, 1)) }}</span>
        @if (filled($photo))
            <img
                src="{{ $photo }}" alt="{{ $name }}" loading="{{ $lazy ? 'lazy' : 'eager' }}" decoding="async" draggable="false"
                onerror="this.remove()" class="absolute inset-0 size-full rounded-full object-cover"
            >
        @endif
    </span>
    @if ($checked)
        <span aria-hidden="true" class="absolute -bottom-1 -end-1 flex size-[22px] motion-safe:animate-chip-pop items-center justify-center rounded-full border-2 border-navy bg-teal text-navy">
            <x-lamma.icon name="check" :size="12" :stroke="3.5" />
        </span>
    @endif
</span>
