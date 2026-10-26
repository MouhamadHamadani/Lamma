{{-- Google Analytics, the private way (App\Support\Analytics, resources/js/analytics.js). Renders nothing unless GOOGLE_ANALYTICS_ID is a valid measurement ID in production,
     and never on the admin panel. gtag.js itself is NOT in this markup: the script only loads after the visitor accepts the notice, so nothing reaches Google before that.
     The ID comes from config, never from a view. The page is described by a sanitized path (route pattern, no query string: never a room code or a token) and a route name. --}}
@if (\App\Support\Analytics::enabled())
    <meta name="lamma-analytics-page" data-path="{{ \App\Support\Analytics::path() }}" data-title="{{ \App\Support\Analytics::title() }}">
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag() { dataLayer.push(arguments); }
        // Consent Mode v2: everything denied until the visitor accepts.
        gtag('consent', 'default', { analytics_storage: 'denied', ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied' });
        gtag('js', new Date());
        // No automatic page_view (it would send the real URL): resources/js/analytics.js sends one with the sanitized path. No Google Signals, no ad personalization.
        gtag('config', @js(\App\Support\Analytics::id()), { send_page_view: false, allow_google_signals: false, allow_ad_personalization_signals: false, cookie_flags: 'SameSite=Lax;Secure' });
        window.lammaAnalytics = { id: @js(\App\Support\Analytics::id()), queue: @js(\App\Support\Analytics::pull()) };
    </script>
@endif
