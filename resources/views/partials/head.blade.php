<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="csrf-token" content="{{ csrf_token() }}" />

<title>
    {{ filled($title ?? null) ? __($title).' - '.config('app.name') : config('app.name') }}
</title>

@if ($share ?? false)
    {{-- The public pages (landing, join, demo) are what people paste into a chat: the title, a description and a 1200x630 picture, in the visitor's language
         (a crawler sends its own Accept-Language, so a link preview is in the language of whoever asked). Never on a host or a phone game screen: those carry a room. --}}
    @php
        $shareTitle = filled($title ?? null) ? __($title).' - '.config('app.name') : config('app.name');
        $shareText = $shareDescription ?? __('A multiplayer quiz party game: one big screen, everyone answers from their phone.');
        $shareImage = asset('brand/og-image.png');
        $shareLocale = app()->getLocale();
        $shareLocales = ['ar' => 'ar_AR', 'en' => 'en_US'];
    @endphp
    <meta name="description" content="{{ $shareText }}">
    <link rel="canonical" href="{{ url()->current() }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ config('app.name') }}">
    <meta property="og:title" content="{{ $shareTitle }}">
    <meta property="og:description" content="{{ $shareText }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="{{ $shareImage }}">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="{{ config('app.name') }} · {{ __('The quiz night for everyone') }}">
    <meta property="og:locale" content="{{ $shareLocales[$shareLocale] ?? $shareLocale }}">
    @foreach ($shareLocales as $code => $tag)
        @if ($code !== $shareLocale)
            <meta property="og:locale:alternate" content="{{ $tag }}">
        @endif
    @endforeach
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $shareTitle }}">
    <meta name="twitter:description" content="{{ $shareText }}">
    <meta name="twitter:image" content="{{ $shareImage }}">
    <meta name="twitter:image:alt" content="{{ config('app.name') }} · {{ __('The quiz night for everyone') }}">
@endif

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@include('partials.analytics')

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
