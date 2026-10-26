// Google Analytics, the private way (App\Support\Analytics). The page renders `window.lammaAnalytics` (and the global gtag() with Consent Mode v2 set to denied)
// only when analytics is on; without it everything below is a no-op, and `lammaTrack` still exists so a screen can call it safely.
//
//  - Nothing is loaded or sent until the visitor accepts: gtag.js is added to the page only then (or at load, for someone who accepted before).
//  - One page_view per page, with the SANITIZED path from <meta name="lamma-analytics-page"> and only the referrer's origin, never a URL with a code in it.
//    A real Livewire navigation (wire:navigate / Livewire.navigate) sends another; the first `livewire:navigated` at load does not (same address).
//  - Events: lammaTrack(name, params, onceKey). Livewire actions dispatch an `analytics` event, redirecting ones flash theirs in the session and the next
//    page replays them (cfg.queue). Everything is dropped without consent.
const cfg = window.lammaAnalytics;
const KEY = 'lamma_consent';

const read = () => {
    try {
        return localStorage.getItem(KEY);
    } catch {
        return null;
    }
};
const write = (value) => {
    try {
        localStorage.setItem(KEY, value);
    } catch {
        // Private mode or blocked storage: the choice only lasts for this page.
    }
};

let granted = cfg !== undefined && read() === 'granted';
let loaded = false;
let lastHref = location.href;
let pending = cfg ? [...cfg.queue] : [];

function sendPageView() {
    const meta = document.querySelector('meta[name="lamma-analytics-page"]');
    if (!granted || !meta) return;

    let referrer = '';
    try {
        referrer = document.referrer ? new URL(document.referrer).origin : '';
    } catch {
        referrer = '';
    }

    gtag('event', 'page_view', {
        page_path: meta.dataset.path,
        page_location: location.origin + meta.dataset.path,
        page_title: meta.dataset.title,
        page_referrer: referrer,
    });
}

function track(name, params = {}, once = null) {
    if (!granted) return;

    if (once) {
        try {
            if (sessionStorage.getItem('lamma_once_' + once)) return;
            sessionStorage.setItem('lamma_once_' + once, '1');
        } catch {
            // No storage: fire it (a repeat is better than losing it).
        }
    }

    gtag('event', name, params);
}

function replay() {
    pending.splice(0).forEach((event) => track(event.name, event.params));
}

function load() {
    if (loaded) return;
    loaded = true;

    const script = document.createElement('script');
    script.async = true;
    script.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(cfg.id);
    document.head.appendChild(script);
}

function clearCookies() {
    document.cookie
        .split(';')
        .map((cookie) => cookie.split('=')[0].trim())
        .filter((name) => name === '_ga' || name.startsWith('_ga_'))
        .forEach((name) => {
            document.cookie = name + '=; Max-Age=0; path=/';
            document.cookie = name + '=; Max-Age=0; path=/; domain=' + location.hostname;
        });
}

function accept() {
    write('granted');
    granted = true;
    gtag('consent', 'update', { analytics_storage: 'granted' });
    sendPageView();
    replay();
    load();
}

function decline() {
    write('denied');
    granted = false;
    pending = [];
    gtag('consent', 'update', { analytics_storage: 'denied' });
    clearCookies();
}

window.lammaTrack = cfg ? track : () => {};

if (cfg) {
    window.addEventListener('analytics', (event) => track(event.detail.name, event.detail.params ?? {}));

    document.addEventListener('livewire:navigated', () => {
        if (location.href === lastHref) return;
        lastHref = location.href;
        sendPageView();
    });

    if (granted) {
        gtag('consent', 'update', { analytics_storage: 'granted' });
        sendPageView();
        replay();
        load();
    }
}

// The notice (<x-lamma.consent-banner>): shown until a choice is made, and again from "Cookie settings".
document.addEventListener('alpine:init', () => {
    window.Alpine.data('lammaConsent', () => ({
        open: false,

        init() {
            this.open = cfg !== undefined && read() === null;
            window.addEventListener('lamma-consent-open', () => (this.open = true));
        },

        accept() {
            accept();
            this.open = false;
        },

        decline() {
            decline();
            this.open = false;
        },
    }));
});
