{{-- "Share result": draws the result card (1080x1350, the winner, the top three, the viewer's place, the site address) in the browser and shares it as a PNG through
     the Web Share API where the browser can, or downloads it. The card's data comes from App\Game\ShareCard (nothing private). Alpine `lammaShare`
     (resources/js/share-card.js). `wire:ignore` keeps a Livewire refresh from resetting its state. The status line is read out and shown (a fade, or nothing under
     reduced motion). `onDark` for the navy host screen. --}}
@props(['card', 'onDark' => false])
<div
    wire:ignore x-data="lammaShare({{ Js::from($card) }}, {{ Js::from(['shared' => __('Result shared'), 'saved' => __('Result saved to your device'), 'failed' => __("We couldn't make the picture. Try again.")]) }})"
    {{ $attributes->class('flex flex-col gap-2') }}
>
    <x-lamma.button variant="outline" :on-dark="$onDark" icon="share" x-on:click="share()" x-bind:disabled="busy" x-bind:aria-busy="busy" data-test="share-button">{{ __('Share result') }}</x-lamma.button>
    <p role="status" aria-live="polite" x-show="status" x-cloak x-text="status" @class(['text-center text-sm font-semibold motion-safe:animate-fade-in', 'text-ink-muted' => ! $onDark, 'text-ink-on-dark' => $onDark]) data-test="share-status"></p>
</div>
