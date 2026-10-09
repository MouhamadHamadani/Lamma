{{-- Shell for the error and maintenance pages: cream page, logo, one rounded number tile with a sticker shadow, one line of copy and the
     buttons (the slot). It must render when the database, the session or the cache is down, so: no query, no Livewire, no Echo, no
     script at all. The page language is chosen by the errors::* view composer (App\Support\ErrorPage).
     tone: sun | coral | teal (the tile). bilingual: show Arabic and English together (the maintenance page, which is rendered once by
     `php artisan down` and served to everyone), and skip the language switcher. inline: put the built CSS in the page (same reason). --}}
@props(['code', 'title', 'message', 'tone' => 'sun', 'bilingual' => false, 'inline' => false])
@php
    $locale = $bilingual ? 'ar' : app()->getLocale();
    $css = $inline ? \App\Support\ErrorPage::inlineCss() : null;
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ config("locales.supported.{$locale}.dir", 'ltr') }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <meta name="robots" content="noindex" />
        <title>{{ $code }} · {{ $title }} - {{ config('app.name') }}</title>
        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        @if ($css !== null)
            <style>{!! $css !!}</style>
        @else
            {!! \App\Support\ErrorPage::assets() !!}
        @endif
    </head>
    <body class="lamma min-h-dvh bg-cream font-sans text-navy antialiased">
        <main class="relative flex min-h-dvh flex-col items-center justify-center gap-7 px-5 py-20 text-center">
            @unless ($bilingual)
                <x-lamma.language-switcher class="absolute end-5 top-5" />
            @endunless

            <a href="{{ route('home') }}" class="absolute start-5 top-5"><x-lamma.logo :locale="$locale" /></a>

            <div
                @class([
                    'flex size-[clamp(168px,44vw,232px)] -rotate-3 items-center justify-center rounded-panel border-3 border-navy font-display text-[clamp(64px,17vw,104px)] font-extrabold leading-none shadow-sticker-lg',
                    'bg-sun' => $tone === 'sun',
                    'bg-coral' => $tone === 'coral',
                    'bg-teal' => $tone === 'teal',
                ])
                dir="ltr" role="img" aria-label="{{ __('Error :code', ['code' => $code]) }}" data-test="error-code"
            >{{ $code }}</div>

            <div class="flex max-w-[34rem] flex-col gap-3">
                <h1 class="font-display text-[clamp(32px,6vw,48px)] font-extrabold leading-[1.1]">{{ $title }}</h1>
                <p class="text-lg leading-relaxed text-ink-muted">{{ $message }}</p>
                {{ $extra ?? '' }}
            </div>

            <div class="flex flex-col items-stretch gap-3 sm:flex-row sm:items-center">
                {{ $slot }}
            </div>
        </main>
    </body>
</html>
