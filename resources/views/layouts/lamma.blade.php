{{-- Shell for the Lamma screens (landing, host, player): cream page, navy text, lang/dir from the current locale. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ config('locales.supported.'.app()->getLocale().'.dir', 'ltr') }}">
    <head>
        @include('partials.head')
        @livewireStyles
    </head>
    <body class="lamma min-h-dvh bg-cream font-sans text-navy antialiased">
        {{ $slot }}

        {{-- Explicit so Alpine (timers, segmented control) also loads on pages without a Livewire component. --}}
        @livewireScripts
    </body>
</html>
