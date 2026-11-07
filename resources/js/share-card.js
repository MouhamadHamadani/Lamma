/**
 * The result share card: a 1080x1350 PNG drawn in the browser on a canvas (the browser shapes Arabic correctly; a server-side image library does not).
 *
 * It shows the winner, the top three and, on a phone, the player's own place, with the brand colours (read from the CSS tokens, never hard-coded),
 * Baloo Bhaijaan 2 and the site address. No photos, no room code, no account: only what the server put in `card` (App\Game\ShareCard).
 * The button shares the PNG as a file through the Web Share API where the browser can, and downloads it everywhere else.
 */
const WIDTH = 1080;
const HEIGHT = 1350;
const FONT = '"Baloo Bhaijaan 2"';

const token = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

/** The Arabic block, so a name or sentence is drawn right to left. */
const ARABIC = /[؀-ۿݐ-ݿﭐ-﷿ﹰ-﻿]/;

/** Latin names inside an Arabic sentence stay in one piece (a left-to-right isolate), so the punctuation around them does not jump. */
const isolate = (text) => '⁦' + text + '⁩';

function loadImage(url) {
    return new Promise((resolve) => {
        const image = new Image();
        image.onload = () => resolve(image);
        image.onerror = () => resolve(null);
        image.src = url;
    });
}

/** Draw `text` so it fits `maxWidth`: the font shrinks first, then the text is cut with an ellipsis. */
function fit(ctx, text, maxWidth, size, weight, min = 30) {
    let px = size;
    ctx.font = `${weight} ${px}px ${FONT}, sans-serif`;
    while (ctx.measureText(text).width > maxWidth && px > min) {
        px -= 2;
        ctx.font = `${weight} ${px}px ${FONT}, sans-serif`;
    }
    if (ctx.measureText(text).width <= maxWidth) return text;

    let out = text;
    while (out.length > 1 && ctx.measureText(out + '…').width > maxWidth) out = out.slice(0, -1);
    return out.trimEnd() + '…';
}

function text(ctx, value, x, y, { size, weight = 700, color, align = 'center', maxWidth = WIDTH, rtl = false }) {
    ctx.fillStyle = color;
    ctx.textBaseline = 'alphabetic';
    ctx.direction = rtl || ARABIC.test(value) ? 'rtl' : 'ltr';
    ctx.textAlign = align;
    const shown = fit(ctx, value, maxWidth, size, weight);
    ctx.fillText(shown, x, y);
}

function roundRect(ctx, x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
}

/** The four brand shapes (triangle, circle, square, diamond) scattered as decoration. `mirror` flips them for a right-to-left card. */
function confetti(ctx, colors, mirror) {
    const pieces = [
        [90, 330, 34, 0, 0.3], [960, 250, 26, 1, 0], [180, 1210, 30, 2, 0.4], [920, 1180, 38, 3, 0],
        [1000, 560, 22, 0, -0.4], [70, 700, 24, 1, 0], [980, 880, 28, 2, 0.5], [120, 960, 22, 3, 0],
    ];
    pieces.forEach(([x, y, size, shape, turn], index) => {
        ctx.save();
        ctx.translate(mirror ? WIDTH - x : x, y);
        ctx.rotate(mirror ? -turn : turn);
        ctx.fillStyle = colors[index % colors.length];
        ctx.beginPath();
        if (shape === 0) {
            ctx.moveTo(0, -size);
            ctx.lineTo(size, size * 0.8);
            ctx.lineTo(-size, size * 0.8);
            ctx.closePath();
        } else if (shape === 1) {
            ctx.arc(0, 0, size * 0.9, 0, Math.PI * 2);
        } else if (shape === 2) {
            ctx.rect(-size * 0.8, -size * 0.8, size * 1.6, size * 1.6);
        } else {
            ctx.moveTo(0, -size);
            ctx.lineTo(size, 0);
            ctx.lineTo(0, size);
            ctx.lineTo(-size, 0);
            ctx.closePath();
        }
        ctx.fill();
        ctx.restore();
    });
}

/** Draw the card on a new canvas and return it. `card` is the server's data (see App\Game\ShareCard). */
export async function drawShareCard(card) {
    // The fonts are fetched on first use: draw nothing until the display font really has the letters this card needs.
    const sample = [card.headline, card.eyebrow, card.host, card.me?.label, ...card.top.map((row) => row.name)].filter(Boolean).join(' ');
    try {
        await Promise.all([document.fonts.load(`800 80px ${FONT}`, sample), document.fonts.load(`700 40px ${FONT}`, sample)]);
    } catch {
        // No font loading support: the fallback font draws instead.
    }

    const rtl = card.locale === 'ar';
    const navy = token('--color-navy');
    const cream = token('--color-cream');
    const sun = token('--color-sun');
    const teal = token('--color-teal');
    const coral = token('--color-coral');
    const onDark = token('--color-ink-on-dark');
    const navy700 = token('--color-navy-700');

    const canvas = document.createElement('canvas');
    canvas.width = WIDTH;
    canvas.height = HEIGHT;
    const ctx = canvas.getContext('2d');

    ctx.fillStyle = navy;
    ctx.fillRect(0, 0, WIDTH, HEIGHT);
    confetti(ctx, [coral, teal, sun], rtl);

    // The logo (stacked, for a dark background). If it cannot load the wordmark is drawn instead.
    const logo = card.logo ? await loadImage(card.logo) : null;
    if (logo && logo.width > 0) {
        const height = 190;
        const width = Math.min(520, (logo.width / logo.height) * height);
        ctx.drawImage(logo, (WIDTH - width) / 2, 50, width, height);
    } else {
        text(ctx, card.brand, WIDTH / 2, 190, { size: 120, weight: 800, color: cream, rtl });
    }

    text(ctx, card.eyebrow, WIDTH / 2, 320, { size: 40, weight: 700, color: sun, maxWidth: 900, rtl });
    text(ctx, card.headline, WIDTH / 2, 420, { size: 96, weight: 800, color: cream, maxWidth: 960, rtl });

    // The top three.
    const rows = card.top.slice(0, 3);
    const colors = { 1: sun, 2: teal, 3: coral };
    const rowHeight = 140;
    const gap = 22;
    // Without the viewer's own band (the big screen) the rows sit a little lower, so the card is not empty at the bottom.
    const top = card.me ? 480 : 520;
    rows.forEach((row, index) => {
        const y = top + index * (rowHeight + gap);
        ctx.fillStyle = navy700;
        roundRect(ctx, 80, y, WIDTH - 160, rowHeight, 36);
        ctx.fill();

        const side = rtl ? WIDTH - 80 - 24 : 80 + 24;
        const badgeX = rtl ? side - 48 : side + 48;
        ctx.fillStyle = colors[row.rank] ?? coral;
        ctx.beginPath();
        ctx.arc(badgeX, y + rowHeight / 2, 48, 0, Math.PI * 2);
        ctx.fill();
        ctx.lineWidth = 6;
        ctx.strokeStyle = navy;
        ctx.stroke();
        text(ctx, String(row.rank), badgeX, y + rowHeight / 2 + 22, { size: 66, weight: 800, color: navy, rtl: false });

        const nameX = rtl ? badgeX - 48 - 30 : badgeX + 48 + 30;
        const scoreX = rtl ? 80 + 40 : WIDTH - 80 - 40;
        text(ctx, row.name, nameX, y + rowHeight / 2 + 20, { size: 64, weight: 700, color: cream, align: rtl ? 'right' : 'left', maxWidth: 520 });
        text(ctx, String(row.score), scoreX, y + rowHeight / 2 + 22, { size: 64, weight: 800, color: sun, align: rtl ? 'left' : 'right', maxWidth: 220, rtl: false });
    });

    // The player's own place (phones only).
    if (card.me) {
        const y = top + rows.length * (rowHeight + gap) + 4;
        ctx.fillStyle = cream;
        roundRect(ctx, 80, y, WIDTH - 160, 128, 36);
        ctx.fill();
        text(ctx, card.me.label, WIDTH / 2, y + 82, { size: 56, weight: 800, color: navy, maxWidth: 860, rtl });
    }

    text(ctx, card.cta, WIDTH / 2, HEIGHT - 150, { size: 40, weight: 700, color: onDark, maxWidth: 900, rtl });
    // The address is an address: always left to right.
    text(ctx, card.host, WIDTH / 2, HEIGHT - 80, { size: 58, weight: 800, color: sun, maxWidth: 900, rtl: false });

    return canvas;
}

export function cardToBlob(canvas) {
    return new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
}

// For tests and for looking at a card by hand: window.lammaShareCard.draw(card) returns the canvas, .toBlob(canvas) the PNG.
window.lammaShareCard = { draw: drawShareCard, toBlob: cardToBlob };

document.addEventListener('alpine:init', () => {
    window.Alpine.data('lammaShare', (card, messages = {}) => ({
        busy: false,
        status: '',

        async make() {
            return cardToBlob(await drawShareCard(card));
        },

        async share() {
            if (this.busy) return;
            this.busy = true;
            this.status = '';

            try {
                const blob = await this.make();
                if (!blob) throw new Error('no image');

                const file = new File([blob], card.filename, { type: 'image/png' });
                let method = 'download';

                if (navigator.canShare && navigator.canShare({ files: [file] })) {
                    try {
                        await navigator.share({ files: [file], title: card.shareTitle, text: card.shareText });
                        method = 'share';
                    } catch (error) {
                        // The person closed the share sheet: nothing was shared and nothing is saved.
                        if (error && error.name === 'AbortError') return;
                        this.download(blob);
                    }
                } else {
                    this.download(blob);
                }

                window.lammaTrack?.('share_clicked', { surface: card.surface, method });
                this.status = method === 'share' ? messages.shared : messages.saved;
            } catch {
                this.status = messages.failed;
            } finally {
                this.busy = false;
            }
        },

        download(blob) {
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = card.filename;
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 10000);
        },
    }));
});
