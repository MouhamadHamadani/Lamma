{{-- Phone countdown bar. Same props and behaviour as <x-lamma.timer-ring>. --}}
@props(['endsAt', 'seconds' => 20])
@php
    $end = \Illuminate\Support\Carbon::parse($endsAt)->getTimestampMs();
    $now = now()->getTimestampMs();
    $left = max(0, $end - $now);
    $secs = (int) ceil($left / 1000);
@endphp
<div
    x-data="lammaTimer({{ $end }}, {{ $now }}, {{ $seconds }}, {{ Js::from(__(':seconds seconds left')) }}, true)"
    role="timer" dir="ltr"
    {{ $attributes->class('flex items-center gap-3') }}
>
    <div class="h-3.5 grow overflow-hidden rounded-chip border-2 border-navy bg-white" aria-hidden="true">
        <div
            class="h-full {{ $secs <= 5 ? 'bg-coral' : 'bg-sun' }}"
            style="width: {{ round(min(1, $left / ($seconds * 1000)) * 100, 1) }}%"
            x-bind:style="{ width: `${frac * 100}%` }"
            x-bind:class="{ 'bg-coral': secs <= 5, 'bg-sun': secs > 5 }"
        ></div>
    </div>
    <span
        x-text="secs" x-bind:class="{ 'motion-safe:animate-tick': secs <= 5 && secs > 0 }"
        class="min-w-11 text-center font-display text-[22px] font-extrabold"
    >{{ $secs }}</span>
    <span class="sr-only" aria-live="polite" x-text="say"></span>
</div>
