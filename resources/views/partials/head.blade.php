<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="csrf-token" content="{{ csrf_token() }}" />

<title>
  {{ filled($title ?? null) ? __($title) . ' - ' . config('app.name') : config('app.name') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">



{{-- ── Google Analytics (consent-aware) ────────────────────────────── --}}
@if (config('app.google_analytics_id') && app()->environment('production'))
  <script async src="https://www.googletagmanager.com/gtag/js?id={{ config('app.google_analytics_id') }}"></script>
  <script>
    window.dataLayer = window.dataLayer || [];

    function gtag() {
      dataLayer.push(arguments);
    }
    // Only fire if cookie consent has been given
    gtag('js', new Date());
    gtag('config', '{{ config('app.google_analytics_id') }}');
  </script>
@endif

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
