{{-- The host's sound switch (question start, last-5-seconds tick, reveal, podium). Off until pressed; remembered in the browser (resources/js/lamma.js).
     wire:ignore: a Livewire refresh would otherwise reset what x-show set. A toggle button: the label stays "Sounds", aria-pressed says whether they are on, and the icon shows it too. onDark: for the navy results screen. --}}
@props(['onDark' => false])
<button
    type="button" wire:ignore x-data="lammaSoundToggle({{ Js::from(asset('sounds')) }})" x-on:click="toggle()"
    x-bind:aria-pressed="on ? 'true' : 'false'" aria-pressed="false" aria-label="{{ __('Sounds') }}" title="{{ __('Sounds') }}" data-test="sound-toggle"
    {{ $attributes->class([
        'flex size-11 shrink-0 items-center justify-center rounded-full border-2',
        'border-line bg-white text-navy hover:bg-tint-navy' => ! $onDark,
        'border-navy-600 bg-navy-700 text-cream hover:bg-navy-600' => $onDark,
    ]) }}
>
    <x-lamma.icon name="volume" :size="22" x-show="on" x-cloak />
    <x-lamma.icon name="volume-off" :size="22" x-show="! on" />
</button>
