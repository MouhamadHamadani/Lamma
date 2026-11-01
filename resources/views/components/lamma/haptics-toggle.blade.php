{{-- The phone's vibration switch (taps, right and wrong answers). On by default, remembered in this browser, and hidden where the browser cannot vibrate (iPhones).
     wire:ignore for the same reason as the sound toggle: a Livewire refresh would undo what x-show set. --}}
@props(['onDark' => false])
<button
    type="button" wire:ignore x-data="lammaHapticsToggle" x-show="supported" x-cloak x-on:click="toggle()"
    x-bind:aria-pressed="$store.sound.haptics ? 'true' : 'false'" aria-pressed="true" aria-label="{{ __('Vibration') }}" title="{{ __('Vibration') }}" data-test="haptics-toggle"
    {{ $attributes->class([
        'flex size-11 shrink-0 items-center justify-center rounded-full border-2',
        'border-line bg-white text-navy hover:bg-tint-navy' => ! $onDark,
        'border-navy-600 bg-navy-700 text-cream hover:bg-navy-600' => $onDark,
    ]) }}
>
    <span x-show="$store.sound.haptics" x-cloak><x-lamma.icon name="vibrate" :size="22" /></span>
    <span x-show="! $store.sound.haptics"><x-lamma.icon name="vibrate-off" :size="22" /></span>
</button>
