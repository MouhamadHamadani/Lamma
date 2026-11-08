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

    // The big screen says so on every channel authorization, so that a host screen opened in a browser that also holds a player row (a second tab on the same laptop) is still the host
    // and not that player (RoomPresence::hostScreenFor believes it only when the request really is the room's host). Phones never send it.
    const role = document.documentElement.dataset.realtimeRole;

    window.Echo = new Echo({
        auth: { headers: role === 'host' ? { 'X-Lamma-Role': 'host' } : {} },
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: host,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}
