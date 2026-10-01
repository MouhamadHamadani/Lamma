{{-- Mark + wordmark. EN: cream with navy outline. AR: coral with navy outline. On navy: plain cream, no outline. --}}
@props(['size' => 'md', 'locale' => null, 'onDark' => false])
@php
    $locale ??= app()->getLocale();
    [$mark, $text] = ['sm' => [34, 'text-[22px]'], 'md' => [48, 'text-[30px]'], 'lg' => [64, 'text-[40px]']][$size];
@endphp
<span {{ $attributes->class('inline-flex items-center gap-2.5') }}>
    <img src="{{ asset('brand/lamma-icon.svg') }}" alt="" width="{{ $mark }}" class="h-auto">
    <span
        lang="{{ $locale }}"
        @class([
            $text,
            'font-display font-extrabold leading-none text-cream' => $onDark,
            'wordmark' => ! $onDark,
            'text-cream' => ! $onDark && $locale !== 'ar',
            'text-coral' => ! $onDark && $locale === 'ar',
        ])
    >{{ $locale === 'ar' ? 'لمّة' : 'Lamma' }}</span>
</span>
