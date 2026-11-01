// Sound and haptics: one Alpine store, `$store.sound`, on the Web Audio API (no audio library).
// - A browser only allows sound after a tap or a click, so the store stays silent until the first gesture on the page unlocks it (`unlock()`).
// - A sound plays from its file in public/sounds when the page lists one (<x-lamma.sound-toggle> passes the file names), otherwise a short
//   synthesized sound from SYNTH below plays: nothing needs to be downloaded or licensed, and an official file replaces a sound by name.
// - A host's sound is ON by default (once unlocked), a phone's is OFF so a room full of phones does not beep. The choice, the volume and the
//   haptics switch are remembered per device in localStorage. With the OS set to reduced motion the default is off too.
const MAX_VOICES = 6;

// A sound whose file in public/sounds is named otherwise (those files were made before the sound list existed).
const FILE_OF = { 'timer-tick': 'tick', win: 'podium' };

// Vibration patterns in milliseconds (phones that support navigator.vibrate).
const BUZZ = { tap: 10, correct: 30, wrong: [20, 40, 20] };

const reduced = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const remembered = (key) => {
    try {
        return localStorage.getItem(key);
    } catch (e) {
        return null;
    }
};

const remember = (key, value) => {
    try {
        localStorage.setItem(key, value);
    } catch (e) {
        // not remembered, still works for this page
    }
};

// One enveloped oscillator starting `at` seconds from now; `to` slides its pitch. The envelope never starts or ends at zero, so nothing clicks.
const tone = (c, out, freq, at, length, { type = 'sine', gain = 0.3, to } = {}) => {
    const t = c.currentTime + at;
    const osc = c.createOscillator();
    const amp = c.createGain();
    osc.type = type;
    osc.frequency.setValueAtTime(freq, t);
    if (to) osc.frequency.exponentialRampToValueAtTime(to, t + length);
    amp.gain.setValueAtTime(0.0001, t);
    amp.gain.exponentialRampToValueAtTime(gain, t + 0.008);
    amp.gain.exponentialRampToValueAtTime(0.0001, t + length);
    osc.connect(amp).connect(out);
    osc.start(t);
    osc.stop(t + length + 0.02);
};

// Band-passed noise sweeping from `from` to `to` Hz: a swoosh.
const swoosh = (c, out, at, length, from, to, gain = 0.25) => {
    const t = c.currentTime + at;
    const buffer = c.createBuffer(1, Math.ceil(c.sampleRate * length), c.sampleRate);
    const data = buffer.getChannelData(0);
    for (let i = 0; i < data.length; i++) data[i] = Math.random() * 2 - 1;
    const src = c.createBufferSource();
    const filter = c.createBiquadFilter();
    const amp = c.createGain();
    src.buffer = buffer;
    filter.type = 'bandpass';
    filter.Q.value = 1.1;
    filter.frequency.setValueAtTime(from, t);
    filter.frequency.exponentialRampToValueAtTime(to, t + length);
    amp.gain.setValueAtTime(0.0001, t);
    amp.gain.exponentialRampToValueAtTime(gain, t + length * 0.4);
    amp.gain.exponentialRampToValueAtTime(0.0001, t + length);
    src.connect(filter).connect(amp).connect(out);
    src.start(t);
};

// The synthesized sounds, soft and short (each under 1.2 s). A function draws one onto `out` at pitch `r` (1 = as written) and returns its length in seconds.
const SYNTH = {
    tap: (c, out, r) => {
        tone(c, out, 880 * r, 0, 0.07, { gain: 0.22 });
        return 0.1;
    },
    'lock-in': (c, out) => {
        tone(c, out, 523, 0, 0.09, { type: 'triangle' });
        tone(c, out, 784, 0.07, 0.16, { type: 'triangle' });
        return 0.25;
    },
    join: (c, out) => {
        tone(c, out, 380, 0, 0.14, { to: 900 });
        tone(c, out, 1300, 0.11, 0.07, { gain: 0.1 });
        return 0.2;
    },
    'ready-on': (c, out) => {
        tone(c, out, 660, 0, 0.1, { type: 'triangle' });
        tone(c, out, 990, 0.09, 0.22, { type: 'triangle' });
        return 0.32;
    },
    'ready-off': (c, out) => {
        tone(c, out, 660, 0, 0.1, { type: 'triangle', gain: 0.22 });
        tone(c, out, 440, 0.09, 0.2, { type: 'triangle', gain: 0.22 });
        return 0.3;
    },
    'countdown-tick': (c, out) => {
        tone(c, out, 700, 0, 0.12, { gain: 0.3 });
        return 0.15;
    },
    'countdown-go': (c, out) => {
        [523, 659, 784, 1047].forEach((f, i) => tone(c, out, f, i * 0.04, 0.55, { type: 'triangle', gain: 0.16 }));
        return 0.7;
    },
    'question-start': (c, out) => {
        swoosh(c, out, 0, 0.3, 400, 3000, 0.2);
        tone(c, out, 880, 0.18, 0.6, { gain: 0.2 });
        tone(c, out, 1320, 0.26, 0.5, { gain: 0.12 });
        return 0.8;
    },
    'timer-tick': (c, out) => {
        tone(c, out, 1000, 0, 0.05, { gain: 0.16 });
        return 0.08;
    },
    'time-up': (c, out) => {
        tone(c, out, 150, 0, 0.5, { type: 'triangle', gain: 0.3, to: 100 });
        return 0.55;
    },
    correct: (c, out) => {
        tone(c, out, 784, 0, 0.18, { type: 'triangle', gain: 0.25 });
        tone(c, out, 1175, 0.12, 0.45, { type: 'triangle', gain: 0.25 });
        tone(c, out, 2350, 0.12, 0.3, { gain: 0.05 });
        return 0.6;
    },
    wrong: (c, out) => {
        tone(c, out, 230, 0, 0.3, { to: 110, gain: 0.35 });
        swoosh(c, out, 0, 0.12, 300, 150, 0.08);
        return 0.35;
    },
    reveal: (c, out) => {
        swoosh(c, out, 0, 0.45, 300, 2600, 0.25);
        tone(c, out, 200, 0, 0.4, { to: 420, gain: 0.15 });
        return 0.5;
    },
    'score-count': (c, out, r) => {
        tone(c, out, 1400 * r, 0, 0.03, { gain: 0.1 });
        return 0.05;
    },
    'rank-up': (c, out) => {
        [523, 659, 784].forEach((f, i) => tone(c, out, f, i * 0.06, 0.12, { type: 'triangle', gain: 0.2 }));
        return 0.3;
    },
    streak: (c, out) => {
        [880, 1109, 1319, 1760].forEach((f, i) => tone(c, out, f, i * 0.06, 0.18, { gain: 0.14 }));
        return 0.45;
    },
    'podium-bar': (c, out, r) => {
        tone(c, out, 160 * r, 0, 0.25, { to: 60 * r, gain: 0.5 });
        return 0.3;
    },
    win: (c, out) => {
        [523, 659, 784].forEach((f, i) => tone(c, out, f, i * 0.12, 0.2, { type: 'triangle', gain: 0.2 }));
        [523, 659, 784, 1047].forEach((f) => tone(c, out, f, 0.4, 0.7, { type: 'triangle', gain: 0.14 }));
        return 1.1;
    },
};

document.addEventListener('alpine:init', () => {
    // The audio graph lives outside the store: Alpine would wrap every object in it in a reactive proxy.
    const audio = { ctx: null, master: null, buffers: {}, voices: 0, loading: new Set() };

    const store = () => window.Alpine.store('sound');

    window.Alpine.store('sound', {
        enabled: false,
        volume: Number(remembered('lamma:volume') ?? 1) || 1,
        haptics: remembered('lamma:haptics') !== '0',
        base: '/sounds',
        files: [],
        // The names of the last sounds that really started (not the ones asked for while muted or locked): lets a test see what played.
        played: [],

        // Called by the toggle button of a screen. `role` ('host' or 'phone') decides the default; what the person chose before wins.
        configure({ role, base, files }) {
            const saved = remembered('lamma:sound');
            this.enabled = saved === null ? role === 'host' && !reduced() : saved === '1';
            this.base = base;
            this.files = files;
            this.preload();
        },

        isMuted() {
            return !this.enabled;
        },

        mute() {
            this.enabled = false;
            remember('lamma:sound', '0');
        },

        unmute() {
            this.enabled = true;
            remember('lamma:sound', '1');
            this.unlock();
        },

        setVolume(volume) {
            this.volume = Math.min(1, Math.max(0, volume));
            remember('lamma:volume', String(this.volume));
            if (audio.master) audio.master.gain.value = 0.5 * this.volume;
        },

        setHaptics(on) {
            this.haptics = on;
            remember('lamma:haptics', on ? '1' : '0');
        },

        // Creates (or wakes) the one shared AudioContext. Only a tap, click or key press may do this, so it is called from the first of them.
        unlock() {
            const Context = window.AudioContext || window.webkitAudioContext;
            if (!Context) return;
            try {
                if (!audio.ctx) {
                    audio.ctx = new Context();
                    // A limiter after the master gain keeps a pile-up of sounds from clipping.
                    audio.master = audio.ctx.createGain();
                    const limiter = audio.ctx.createDynamicsCompressor();
                    audio.master.connect(limiter).connect(audio.ctx.destination);
                    audio.master.gain.value = 0.5 * this.volume;
                }
                if (audio.ctx.state === 'suspended') audio.ctx.resume().catch(() => {});
                this.preload();
            } catch (e) {
                // no Web Audio: stay silent
            }
        },

        // Fetches and decodes the files of public/sounds once. A missing or broken file is skipped: its synthesized sound plays instead.
        async preload() {
            if (!audio.ctx) return;
            for (const name of Object.keys(SYNTH)) {
                const file = FILE_OF[name] ?? name;
                if (!this.files.includes(file) || audio.buffers[name] || audio.loading.has(name)) continue;
                audio.loading.add(name);
                try {
                    const bytes = await (await fetch(`${this.base}/${file}.wav`)).arrayBuffer();
                    audio.buffers[name] = await audio.ctx.decodeAudioData(bytes);
                } catch (e) {
                    // fall back to the synthesized sound
                }
            }
        },

        // Plays a sound by name; `rate` shifts the pitch, `volume` scales it. Silent (and harmless) until unlocked, while muted, and past 6 voices.
        play(name, { volume = 1, rate = 1 } = {}) {
            if (!this.enabled || audio.ctx?.state !== 'running' || audio.voices >= MAX_VOICES || !SYNTH[name]) return;

            this.played.push(name);
            if (this.played.length > 60) this.played.shift();

            const c = audio.ctx;
            const out = c.createGain();
            out.gain.value = volume;
            out.connect(audio.master);

            let length;
            if (audio.buffers[name]) {
                const src = c.createBufferSource();
                src.buffer = audio.buffers[name];
                src.playbackRate.value = rate;
                src.connect(out);
                src.start();
                length = src.buffer.duration / rate;
            } else {
                length = SYNTH[name](c, out, rate);
            }

            audio.voices++;
            setTimeout(() => {
                audio.voices--;
                out.disconnect();
            }, length * 1000 + 50);
        },

        // A short vibration for a tap, a right answer or a wrong one. Phones that support it, and only after the person has touched the page.
        buzz(kind = 'tap') {
            if (!this.haptics || !('vibrate' in navigator) || navigator.userActivation?.hasBeenActive === false) return;
            try {
                navigator.vibrate(BUZZ[kind] ?? BUZZ.tap);
            } catch (e) {
                // no vibration here
            }
        },
    });

    // The first tap, click or key press unlocks the sound (host: the Start game click; phone: the first tap). Only when sound is on:
    // a phone that never turns it on never creates an AudioContext.
    const events = ['click', 'touchend', 'keydown'];
    const onGesture = () => {
        if (store().enabled) store().unlock();
        if (audio.ctx?.state === 'running') events.forEach((type) => document.removeEventListener(type, onGesture, true));
    };
    events.forEach((type) => document.addEventListener(type, onGesture, true));

    // A server-side `$this->dispatch('lamma-sound', name: 'join')` plays a sound on this screen.
    window.addEventListener('lamma-sound', (event) => store().play(event.detail?.name, event.detail));

    // The speaker button: label "Sounds", aria-pressed says whether they are on. `config` = {role, base, files}.
    window.Alpine.data('lammaSoundToggle', (config) => ({
        init() {
            store().configure(config);
        },

        toggle() {
            const sound = store();
            if (sound.isMuted()) sound.unmute();
            else sound.mute();
            sound.play('tap');
        },
    }));

    // The vibration button of a phone; hidden where the browser has no navigator.vibrate (iPhones).
    window.Alpine.data('lammaHapticsToggle', () => ({
        supported: 'vibrate' in navigator,

        toggle() {
            store().setHaptics(!store().haptics);
            store().buzz('tap');
        },
    }));
});
