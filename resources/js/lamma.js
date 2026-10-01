// Countdown for the timer ring/bar. The server owns the deadline (`ends_at`, epoch ms); the client only displays it.
// `serverNow` (epoch ms at render time) cancels out any skew between the server and device clocks.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('lammaTimer', (endsAt, serverNow, seconds, announcement) => ({
        left: 0,
        say: '',
        interval: null,

        init() {
            const skew = serverNow - Date.now();
            // setInterval, not requestAnimationFrame: rAF stops in background tabs and the deadline must stay right.
            const tick = () => {
                this.left = Math.max(0, endsAt - (Date.now() + skew));
                if (this.left === 0) clearInterval(this.interval);
            };
            tick();
            this.interval = setInterval(tick, 50);
            // Screen readers hear the timer at 10 s and 5 s only, not every second.
            this.$watch('secs', (s) => {
                if (s === 10 || s === 5) this.say = announcement.replace(':seconds', s);
            });
        },

        destroy() {
            clearInterval(this.interval);
        },

        get secs() {
            return Math.ceil(this.left / 1000);
        },

        get frac() {
            return Math.min(1, this.left / (seconds * 1000));
        },
    }));
});

// "Reconnecting…" card: shown while the Echo websocket is down, hidden once it is back. Not shown for the first connect.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('lammaConnection', () => ({
        offline: false,
        wasConnected: false,

        init() {
            const connection = window.Echo?.connector?.pusher?.connection;
            if (!connection) return;

            const update = (state) => {
                if (state === 'connected') {
                    this.wasConnected = true;
                    this.offline = false;
                } else if (['unavailable', 'failed', 'disconnected'].includes(state) || (state === 'connecting' && this.wasConnected)) {
                    this.offline = true;
                }
            };
            update(connection.state);
            connection.bind('state_change', ({ current }) => update(current));
        },
    }));
});
