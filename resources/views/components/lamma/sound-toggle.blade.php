{{-- The speaker switch for the sound effects (resources/js/sound.js, the `sound` store). A toggle button: the label stays "Sounds", aria-pressed says whether they are on,
     and the icon shows it too. The host's sounds start ON (after the first click unlocks audio), a phone's start OFF (`phone`), and the choice is remembered per device.
     The page lists the files in public/sounds; a sound without a file is synthesized. wire:ignore: a Livewire refresh would otherwise reset what x-show set.
     onDark: for the navy results screen. --}}
@props(['onDark' => false, 'phone' => false])
@php
    $files = collect(glob(public_path('sounds/*.wav')) ?: [])->map(fn (string $file) => pathinfo($file, PATHINFO_FILENAME))->values();
    // A root-relative base, not asset(): a phone that reached the laptop by its LAN address must not be sent to the APP_URL "localhost".
    $config = ['role' => $phone ? 'phone' : 'host', 'base' => '/sounds', 'files' => $files];
@endphp
<button
    type="button" wire:ignore x-data="lammaSoundToggle({{ Js::from($config) }})" x-on:click="toggle()"
    x-bind:aria-pressed="$store.sound.enabled ? 'true' : 'false'" aria-pressed="{{ $phone ? 'false' : 'true' }}" aria-label="{{ __('Sounds') }}" title="{{ __('Sounds') }}" data-test="sound-toggle"
    {{ $attributes->class([
        'flex size-11 shrink-0 items-center justify-center rounded-full border-2',
        'border-line bg-white text-navy hover:bg-tint-navy' => ! $onDark,
        'border-navy-600 bg-navy-700 text-cream hover:bg-navy-600' => $onDark,
    ]) }}
>
    <x-lamma.icon name="volume" :size="22" x-show="$store.sound.enabled" :x-cloak="$phone ? '' : null" />
    <x-lamma.icon name="volume-off" :size="22" x-show="! $store.sound.enabled" :x-cloak="$phone ? null : ''" />
</button>
