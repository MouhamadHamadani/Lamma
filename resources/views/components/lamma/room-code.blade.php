{{-- hero: big tilted sticker tiles (host lobby). chip: small pill, the slot is an optional label ("Room"). Always left-to-right. --}}
@props(['code', 'size' => 'hero'])
@php
    $code = strtoupper($code);
    $tiles = ['bg-coral -rotate-3', 'bg-teal rotate-2', 'bg-sun -rotate-2', 'bg-white rotate-3'];
@endphp
@if ($size === 'chip')
    <span dir="ltr" {{ $attributes->class('inline-flex h-10 items-center gap-2 rounded-chip border-2 border-line bg-white px-3.5 text-sm font-bold') }}>
        {{ $slot }}
        <span class="font-display text-lg font-extrabold tracking-[3px]">{{ $code }}</span>
    </span>
@else
    {{-- The font size drives the tile size (em), so one clamp() scales the whole row from laptop to TV. --}}
    <div
        dir="ltr" role="img" aria-label="{{ implode(' ', mb_str_split($code)) }}"
        {{ $attributes->class('flex gap-[.2em] text-[clamp(64px,6.5vw,120px)]') }}
    >
        @foreach (mb_str_split($code) as $n => $char)
            <span
                aria-hidden="true"
                class="{{ $tiles[$n % 4] }} flex h-[1.565em] w-[1.3em] items-center justify-center rounded-tile border-3 border-navy font-display font-extrabold leading-none text-navy shadow-sticker-lg"
            >{{ $char }}</span>
        @endforeach
    </div>
@endif
