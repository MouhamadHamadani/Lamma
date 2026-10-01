import Echo from 'laravel-echo';

import Pusher from 'pusher-js';

// Only the real-time screens (lobby, game) connect: their layout puts data-realtime on <html>. Every other page skips the websocket.
if (document.documentElement.hasAttribute('data-realtime')) {
    window.Pusher = Pusher;

    // A phone that opened the app by the laptop's LAN address must reach Reverb on that same address: "localhost" there is the
    // phone itself. So a loopback VITE_REVERB_HOST is replaced by the page's own host. A real host name is used as configured.
    const loopback = ['', 'localhost', '127.0.0.1', '[::1]'];
    const configured = import.meta.env.VITE_REVERB_HOST ?? '';
    const host = loopback.includes(configured) ? window.location.hostname : configured;

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: host,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}
