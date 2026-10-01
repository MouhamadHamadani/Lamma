{{-- Host countdown ring. `endsAt` is the server deadline (Carbon or date string); `seconds` is the full question length. Drains linearly, turns coral under 5 s. --}}
@props(['endsAt', 'seconds' => 20])
@php
    $end = \Illuminate\Support\Carbon::parse($endsAt)->getTimestampMs();
    $now = now()->getTimestampMs();
    $left = max(0, $end - $now);
    $secs = (int) ceil($left / 1000);
    $circumference = 389.56; // 2π × 62
@endphp
<div
    x-data="lammaTimer({{ $end }}, {{ $now }}, {{ $seconds }}, {{ Js::from(__(':seconds seconds left')) }}, true)"
    role="timer" dir="ltr"
    {{ $attributes->class('relative size-[clamp(104px,16.7dvh,150px)] shrink-0') }}
>
    <svg viewBox="0 0 150 150" class="size-full" aria-hidden="true">
        <circle cx="75" cy="75" r="68" class="fill-white stroke-navy" stroke-width="3" />
        <circle cx="75" cy="75" r="62" fill="none" class="stroke-line" stroke-width="12" />
        <circle
            cx="75" cy="75" r="62" fill="none" stroke-width="12" stroke-linecap="round" transform="rotate(-90 75 75)"
            class="{{ $secs <= 5 ? 'stroke-coral' : 'stroke-sun' }} transition-colors"
            stroke-dasharray="{{ round(min(1, $left / ($seconds * 1000)) * $circumference, 1) }} {{ $circumference }}"
            x-bind:stroke-dasharray="`${frac * {{ $circumference }}} {{ $circumference }}`"
            x-bind:class="{ 'stroke-coral': secs <= 5, 'stroke-sun': secs > 5 }"
            x-show="left > 0"
        />
    </svg>
    <div class="absolute inset-0 flex flex-col items-center justify-center">
        <span
            x-text="secs" x-bind:class="{ 'motion-safe:animate-tick': secs <= 5 && secs > 0 }"
            class="font-display text-[clamp(38px,6.2dvh,56px)] font-extrabold leading-none"
        >{{ $secs }}</span>
        <span class="text-[13px] font-bold text-ink-subtle">{{ __('seconds') }}</span>
    </div>
    <span class="sr-only" aria-live="polite" x-text="say"></span>
</div>
