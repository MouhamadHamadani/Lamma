// Small canvas confetti in the Lamma shapes: `lammaConfetti.burst(origin, count)` pops pieces from an element (or a point), `lammaConfetti.rain(ms)` lets
// a gentle shower fall from the top. One shared canvas (fixed, pointer-events none, aria-hidden) is made when needed and removed after the last piece
// has landed. At most 120 pieces at once, nothing while the tab is hidden, and nothing at all under reduced motion.
// `tone` colours every piece with one brand colour (the teal burst of the Ready button). `origin` is an Element, or {x, y} as fractions of the screen (mirrored in right-to-left pages), or 'start' / 'end' (the side where reading starts / ends).
const CAP = 120;
const SHAPES = ['triangle', 'circle', 'square', 'diamond'];
const TOKENS = ['coral', 'teal', 'sun', 'navy'];

const reduced = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const rtl = () => document.documentElement.dir === 'rtl';
// The colours come from the CSS tokens, looked up once each (the tokens never change, and reading a style for every piece on every frame would cost).
const colors = new Map();
const color = (name) => {
    if (!colors.has(name)) colors.set(name, getComputedStyle(document.documentElement).getPropertyValue(`--color-${name}`).trim());

    return colors.get(name);
};

let canvas = null;
let ctx = null;
let pieces = [];
let raining = 0;
let frame = 0;
let last = 0;
let dark = false;

const stop = () => {
    cancelAnimationFrame(frame);
    frame = 0;
    pieces = [];
    raining = 0;
    canvas?.remove();
    canvas = ctx = null;
};

const resolve = (origin = { x: 0.5, y: 0.5 }) => {
    const dir = rtl() ? -1 : 1;
    if (origin instanceof Element) {
        const box = origin.getBoundingClientRect();

        return { x: box.left + box.width / 2, y: box.top + box.height / 2, dir };
    }
    if (origin === 'start') return { x: (dir === 1 ? 0.12 : 0.88) * innerWidth, y: 0.6 * innerHeight, dir };
    if (origin === 'end') return { x: (dir === 1 ? 0.88 : 0.12) * innerWidth, y: 0.6 * innerHeight, dir };

    return { x: (dir === 1 ? origin.x : 1 - origin.x) * innerWidth, y: origin.y * innerHeight, dir };
};

const spawn = (x, y, vx, vy, size, light, tone) => {
    const shape = Math.floor(Math.random() * 4);
    pieces.push({
        x, y, vx, vy, size, shape,
        fill: color(tone ?? (light && shape === 3 ? 'cream' : TOKENS[shape])),
        angle: Math.random() * Math.PI * 2,
        spin: (Math.random() - 0.5) * 8,
        sway: Math.random() * Math.PI * 2,
        age: 0,
    });
};

const draw = (p) => {
    const r = p.size / 2;
    ctx.save();
    ctx.translate(p.x, p.y);
    ctx.rotate(p.angle);
    ctx.fillStyle = p.fill;
    ctx.beginPath();
    if (p.shape === 0) {
        ctx.moveTo(0, -r);
        ctx.lineTo(r, r);
        ctx.lineTo(-r, r);
        ctx.closePath();
    } else if (p.shape === 1) {
        ctx.arc(0, 0, r, 0, Math.PI * 2);
    } else if (p.shape === 2) {
        ctx.rect(-r, -r, p.size, p.size);
    } else {
        ctx.moveTo(0, -r * 1.2);
        ctx.lineTo(r, 0);
        ctx.lineTo(0, r * 1.2);
        ctx.lineTo(-r, 0);
        ctx.closePath();
    }
    ctx.fill();
    if (!dark) {
        ctx.strokeStyle = color('navy');
        ctx.lineWidth = 1.5;
        ctx.stroke();
    }
    ctx.restore();
};

const step = (now) => {
    const dt = Math.min(0.05, (now - last) / 1000);
    last = now;
    ctx.clearRect(0, 0, innerWidth, innerHeight);

    if (raining > now && pieces.length < CAP && Math.random() < 0.5) {
        spawn(Math.random() * innerWidth, -16, (Math.random() - 0.5) * 30 * (rtl() ? -1 : 1), 90 + Math.random() * 70, 8 + Math.random() * 6, dark);
    }

    pieces = pieces.filter((p) => {
        p.age += dt;
        p.vy += 380 * dt;
        p.vx *= 1 - 1.2 * dt;
        p.vy *= 1 - 0.6 * dt;
        p.x += (p.vx + Math.sin(p.age * 3 + p.sway) * 12) * dt;
        p.y += p.vy * dt;
        p.angle += p.spin * dt;
        if (p.y > innerHeight + 24 || p.age > 6) return false;
        draw(p);

        return true;
    });

    if (pieces.length || raining > now) frame = requestAnimationFrame(step);
    else stop();
};

const start = (onDark) => {
    if (reduced() || document.hidden) return false;
    dark = onDark;
    if (!canvas) {
        const ratio = Math.min(2, window.devicePixelRatio || 1);
        canvas = document.createElement('canvas');
        canvas.setAttribute('aria-hidden', 'true');
        canvas.dataset.confetti = '';
        canvas.className = 'pointer-events-none fixed inset-0 z-50 size-full';
        canvas.width = innerWidth * ratio;
        canvas.height = innerHeight * ratio;
        ctx = canvas.getContext('2d');
        ctx.scale(ratio, ratio);
        document.body.append(canvas);
    }
    if (!frame) {
        last = performance.now();
        frame = requestAnimationFrame(step);
    }

    return true;
};

window.lammaConfetti = {
    burst(origin, count = 24, { onDark = false, tone } = {}) {
        if (!start(onDark)) return;
        const { x, y, dir } = resolve(origin);
        for (let i = 0; i < Math.min(count, CAP - pieces.length); i++) {
            const angle = -Math.PI / 2 + (Math.random() - 0.5) * Math.PI * 1.1;
            const speed = 180 + Math.random() * 280;
            spawn(x, y, Math.cos(angle) * speed + dir * 25, Math.sin(angle) * speed, 7 + Math.random() * 6, onDark, tone);
        }
    },

    rain(ms = 6000, { onDark = true } = {}) {
        if (!start(onDark)) return;
        raining = performance.now() + ms;
    },
};

// A hidden tab pauses the animation frames; drop everything so nothing waits to burst out when the person comes back.
document.addEventListener('visibilitychange', () => {
    if (document.hidden) stop();
});
