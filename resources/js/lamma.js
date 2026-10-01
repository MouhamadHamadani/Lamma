// Countdown for the timer ring/bar. The server owns the deadline (`ends_at`, epoch ms); the client only displays it.
// `serverNow` (epoch ms at render time) cancels out any skew between the server and device clocks.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('lammaTimer', (endsAt, serverNow, seconds, announcement, ticks = false) => ({
        left: 0,
        say: '',
        interval: null,

        init() {
            const skew = serverNow - Date.now();
            // setInterval, not requestAnimationFrame: rAF stops in background tabs and the deadline must stay right.
            const tick = () => {
                this.left = Math.max(0, endsAt - (Date.now() + skew));
                if (this.left === 0) {
                    clearInterval(this.interval);
                    // Lets a screen react when time is up (the host nudges the game engine). Fires once per timer.
                    this.$dispatch('lamma-timer-ended');
                }
            };
            tick();
            this.interval = setInterval(tick, 50);
            // Screen readers hear the timer at 10 s and 5 s only, not every second.
            this.$watch('secs', (s) => {
                if (s === 10 || s === 5) this.say = announcement.replace(':seconds', s);
                // A question's timer (ticks = true) lets the host play a tick for each of the last five seconds (sound store below).
                if (ticks && s <= 5 && s > 0) this.$dispatch('lamma-tick', { secs: s });
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

// Counts a number up from 0 to `to` (the "+100" on the scoreboard). Shows the final value at once under reduced motion.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('lammaCountUp', (to, duration = 400) => ({
        n: to,

        init() {
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || to === 0) return;

            this.n = 0;
            const start = performance.now();
            const step = (now) => {
                const progress = Math.min(1, (now - start) / duration);
                this.n = Math.round(to * progress);
                if (progress < 1) requestAnimationFrame(step);
            };
            requestAnimationFrame(step);
        },
    }));
});

// Host sounds: question start, a tick for the last 5 seconds, the reveal and the podium. OFF by default: the host turns them on with the
// speaker button (<x-lamma.sound-toggle>), and the choice is remembered in this browser. Phones never turn it on, so they stay silent.
// Browsers only allow sound after a click on the page; pressing the toggle counts, and a blocked play() is ignored.
document.addEventListener('alpine:init', () => {
    window.Alpine.store('sound', {
        enabled: false,
        base: '/sounds',

        play(name) {
            if (!this.enabled) return;
            try {
                const audio = new Audio(`${this.base}/${name}.wav`);
                audio.volume = 0.6;
                audio.play().catch(() => {});
            } catch (e) {
                // no audio support: stay silent
            }
        },
    });

    window.addEventListener('lamma-tick', () => window.Alpine.store('sound').play('tick'));

    window.Alpine.data('lammaSoundToggle', (base) => ({
        on: false,

        init() {
            const sound = window.Alpine.store('sound');
            sound.base = base;
            try {
                this.on = localStorage.getItem('lamma:sound') === '1';
            } catch (e) {
                // storage blocked: start off
            }
            sound.enabled = this.on;
        },

        toggle() {
            this.on = !this.on;
            window.Alpine.store('sound').enabled = this.on;
            try {
                localStorage.setItem('lamma:sound', this.on ? '1' : '0');
            } catch (e) {
                // not remembered, still works for this page
            }
            if (this.on) window.Alpine.store('sound').play('tick'); // proves it works, and unlocks audio
        },
    }));
});
