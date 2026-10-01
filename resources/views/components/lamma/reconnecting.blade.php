{{-- Shown over a real-time screen while the websocket is down (HANDOFF §9): a centred card with pulsing dots.
     Driven by Alpine lammaConnection (resources/js/lamma.js); hidden until the connection is lost after being up.
     wire:ignore: a Livewire re-render (the lobby refreshes on every presence event) would otherwise morph away the
     display:none that x-show set and bring the card back. --}}
<div
    wire:ignore x-data="lammaConnection" x-show="offline" x-cloak
    role="alert" aria-live="assertive" data-test="reconnecting"
    class="fixed inset-0 z-50 flex items-center justify-center bg-navy/60 p-6"
>
    <div class="flex flex-col items-center gap-4 rounded-card border-3 border-navy bg-white px-10 py-8 text-center shadow-sticker-lg">
        <p class="font-display text-3xl font-extrabold">{{ __('Reconnecting…') }}</p>
        <x-lamma.dots />
    </div>
</div>
