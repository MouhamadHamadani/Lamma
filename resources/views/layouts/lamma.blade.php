{{-- Shell for the Lamma screens (landing, host, player): cream page, navy text, lang/dir from the current locale. --}}
@props(['title' => null, 'realtime' => false])
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ config('locales.supported.'.app()->getLocale().'.dir', 'ltr') }}" @if ($realtime) data-realtime @endif>
    <head>
        @include('partials.head', ['lamma' => true])
        @livewireStyles
    </head>
    <body class="lamma min-h-dvh bg-cream font-sans text-navy antialiased">
        {{ $slot }}

        {{-- Explicit so Alpine (timers, segmented control) also loads on pages without a Livewire component. --}}
        @livewireScripts
        @stack('scripts')
    </body>
</html>
