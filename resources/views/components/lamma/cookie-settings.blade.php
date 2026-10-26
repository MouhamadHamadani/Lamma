{{-- "Cookie settings": opens the analytics notice again so the visitor can change their mind. Renders only when analytics is on (or in the gallery with `preview`). --}}
@props(['preview' => false])
@if ($preview || \App\Support\Analytics::enabled())
    <button type="button" x-data x-on:click="$dispatch('lamma-consent-open')" {{ $attributes->class('min-h-11 text-start font-medium text-ink-muted hover:text-coral-700') }} data-test="cookie-settings">{{ __('Cookie settings') }}</button>
@endif
