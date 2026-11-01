{{-- The full-screen 3 · 2 · 1 · Go! before question 1, on the host screen and on every phone. `endsAt` is the server's countdown_ends_at: the number is worked out
     from it (like the timers, with the server clock cancelling out any skew), so every screen counts together. A big sticker tile pops in for each number with a
     tick, and "Go!" has a sound of its own (resources/js/lamma.js, lammaCountdown). Under reduced motion the tile only fades. Decorative: the screen's own
     "Get ready…" heading is what a screen reader hears. --}}
@props(['endsAt'])
@php
    $end = \Illuminate\Support\Carbon::parse($endsAt)->getTimestampMs();
    $now = now()->getTimestampMs();
    $n = (int) max(0, ceil(($end - $now) / 1000));
@endphp
<div
    x-data="lammaCountdown({{ $end }}, {{ $now }}, {{ Js::from(__('Go!')) }})" aria-hidden="true" data-test="countdown" data-n="{{ $n }}" x-bind:data-n="n"
    {{ $attributes->class('fixed inset-0 z-40 flex items-center justify-center bg-cream') }}
>
    <div
        x-ref="tile" dir="ltr"
        x-bind:class="{ 'bg-coral': n === 3 || n === 0, 'bg-sun': n === 2, 'bg-teal': n === 1, 'text-[clamp(120px,26dvh,240px)]': n > 0, 'text-[clamp(56px,11dvh,110px)]': n === 0 }"
        @class([
            'flex size-[min(70vw,clamp(200px,42dvh,380px))] items-center justify-center rounded-panel border-3 border-navy font-display font-extrabold leading-none text-navy shadow-sticker-lg',
            'bg-coral' => $n === 3 || $n === 0,
            'bg-sun' => $n === 2,
            'bg-teal' => $n === 1,
            'text-[clamp(120px,26dvh,240px)]' => $n > 0,
            'text-[clamp(56px,11dvh,110px)]' => $n === 0,
        ])
    ><span x-text="label" data-test="countdown-label">{{ $n > 0 ? $n : __('Go!') }}</span></div>
</div>
