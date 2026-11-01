// Countdown for the timer ring/bar. The server owns the deadline (`ends_at`, epoch ms); the client only displays it.
// `serverNow` (epoch ms at render time) cancels out any skew between the server and device clocks.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('lammaTimer', (endsAt, serverNow, seconds, announcement, ticks = false) => ({
        left: 0,
        say: '',
        interval: null,

        init() {
            const skew = serverNow - Date.now();
            let running = false;
            // setInterval, not requestAnimationFrame: rAF stops in background tabs and the deadline must stay right.
            const tick = () => {
                this.left = Math.max(0, endsAt - (Date.now() + skew));
                if (this.left > 0) running = true;
                if (this.left === 0) {
                    clearInterval(this.interval);
                    // A question's timer (ticks = true) that ran down on this screen ends with a gentle "time's up" (not when the page opened after the deadline).
                    if (ticks && running) window.Alpine.store('sound').play('time-up');
                    // Lets a screen react when time is up (the host nudges the game engine). Fires once per timer.
                    this.$dispatch('lamma-timer-ended');
                }
            };
            tick();
            this.interval = setInterval(tick, 50);
            // Screen readers hear the timer at 10 s and 5 s only, not every second.
            this.$watch('secs', (s) => {
                if (s === 10 || s === 5) this.say = announcement.replace(':seconds', s);
                // A question's timer (ticks = true) ticks for each of the last five seconds (resources/js/sound.js).
                if (ticks && s <= 5 && s > 0) window.Alpine.store('sound').play('timer-tick');
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

// Counts a number up from 0 to `to` (the "+100" on the scoreboard). Shows the final value at once under reduced motion. With `sound`, very short ticks
// rise in pitch while it counts (one number per screen should ask for them, or the ticks pile up).
document.addEventListener('alpine:init', () => {
    window.Alpine.data('lammaCountUp', (to, duration = 400, sound = false) => ({
        n: to,

        init() {
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || to === 0) return;

            this.n = 0;
            const start = performance.now();
            let lastTick = 0;
            const step = (now) => {
                const progress = Math.min(1, (now - start) / duration);
                this.n = Math.round(to * progress);
                if (sound && progress < 1 && now - lastTick > 60) {
                    lastTick = now;
                    window.Alpine.store('sound').play('score-count', { rate: 1 + progress * 0.6 });
                }
                if (progress < 1) requestAnimationFrame(step);
            };
            requestAnimationFrame(step);
        },
    }));
});

// The 3 · 2 · 1 · Go! overlay (<x-lamma.countdown>). The number is worked out from the server's `countdown_ends_at` (with the server clock cancelling any skew,
// like the timers), so the host and every phone count together. A tick for each number, a "go" at the end; the tile pops in each time (resources/js/fx.js).
document.addEventListener('alpine:init', () => {
    window.Alpine.data('lammaCountdown', (endsAt, serverNow, goLabel) => ({
        n: 3,
        label: '',
        interval: null,

        init() {
            const skew = serverNow - Date.now();
            let shown = null;
            const tick = () => {
                const n = Math.max(0, Math.ceil((endsAt - (Date.now() + skew)) / 1000));
                if (n === shown) return;
                shown = n;
                this.n = n;
                this.label = n > 0 ? String(n) : goLabel;
                const sound = window.Alpine.store('sound');
                sound.play(n > 0 ? 'countdown-tick' : 'countdown-go');
                sound.buzz('tap');
                window.lammaFx.pop(this.$refs.tile, n > 0);
                if (n === 0) clearInterval(this.interval);
            };
            tick();
            this.interval = setInterval(tick, 50);
        },

        destroy() {
            clearInterval(this.interval);
        },
    }));
});
