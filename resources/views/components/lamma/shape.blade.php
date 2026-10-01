{{-- Answer shape: 0 triangle, 1 circle, 2 square, 3 diamond. Filled with currentColor; always decorative (aria-hidden). --}}
@props(['index' => 0, 'size' => 32])
<svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 18 18" fill="currentColor" aria-hidden="true" {{ $attributes->class('shrink-0') }}>
    @switch($index % 4)
        @case(0) <path d="M9 2 L16.5 15.5 H1.5 Z"/> @break
        @case(1) <circle cx="9" cy="9" r="7.5"/> @break
        @case(2) <rect x="2" y="2" width="14" height="14" rx="2"/> @break
        @default <path d="M9 1 L17 9 L9 17 L1 9 Z"/>
    @endswitch
</svg>
