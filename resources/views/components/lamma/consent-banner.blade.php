{{-- The analytics notice (Consent Mode v2: everything is denied until the visitor chooses). Rendered ONLY when analytics is on (App\Support\Analytics::enabled()),
     and only on the landing page, the join page and the phone results: never on the host screen or while a question is open, and never over the controls of the screen it
     sits on (`floating` puts it in a corner of the landing page; the others place it in the page flow). The choice is kept in localStorage (resources/js/analytics.js);
     <x-lamma.cookie-settings> opens it again. `preview` shows it statically (the component gallery). --}}
@props(['floating' => false, 'preview' => false])
@if ($preview || \App\Support\Analytics::enabled())
    <section
        x-data="{!! $preview ? '{ open: true, accept() {}, decline() {} }' : 'lammaConsent' !!}" x-show="open" style="display: none" wire:ignore
        role="region" aria-labelledby="consent-title" data-test="consent-banner"
        {{ $attributes->class([
            'flex flex-col gap-3 rounded-card border-2 border-line bg-white p-4',
            'fixed bottom-4 start-4 end-4 z-40 sm:end-auto sm:max-w-[420px]' => $floating,
        ]) }}
    >
        <div class="flex flex-col gap-1">
            <h2 id="consent-title" class="font-display text-lg font-extrabold leading-tight">{{ __('Help us improve Lamma') }}</h2>
            <p class="text-sm leading-relaxed text-ink-muted">{{ __('We use Google Analytics to count visits and see which features people use. It never receives names, e-mail addresses or room codes.') }}</p>
        </div>
        <div class="flex flex-wrap gap-2.5">
            <x-lamma.button x-on:click="accept()" class="grow" data-test="consent-accept">{{ __('Accept') }}</x-lamma.button>
            <x-lamma.button variant="outline" x-on:click="decline()" class="grow" data-test="consent-decline">{{ __('Decline') }}</x-lamma.button>
        </div>
    </section>
@endif
