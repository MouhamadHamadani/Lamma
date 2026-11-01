// One-off flourishes with the Web Animations API: a pop, a wobble, a shake, a squish. Used where a CSS class would be lost when Livewire morphs the
// page. Under reduced motion each of them is a plain fade: nothing moves, nothing pulses.
const reduced = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const run = (el, frames, options) => {
    if (!el || !el.animate) return null;

    return el.animate(reduced() ? [{ opacity: 0.5 }, { opacity: 1 }] : frames, { duration: 400, easing: 'ease-out', ...options });
};

window.lammaFx = {
    // Scales in with a little overshoot (the countdown numbers); with `out` it also eases back out over the rest of the second.
    pop: (el, out = false) => out
        ? run(el, [{ scale: 0.4, opacity: 0 }, { scale: 1.12, opacity: 1, offset: 0.2 }, { scale: 1, offset: 0.35 }, { scale: 1, offset: 0.8 }, { scale: 0.88 }], { duration: 1000, easing: 'linear' })
        : run(el, [{ scale: 0.4, opacity: 0 }, { scale: 1.12, opacity: 1, offset: 0.6 }, { scale: 1 }], { duration: 450, easing: 'cubic-bezier(.34, 1.56, .64, 1)' }),

    // A short attention wobble ("Start game" once everyone is ready).
    wobble: (el) => run(el, [{ rotate: '0deg' }, { rotate: '-3deg' }, { rotate: '3deg' }, { rotate: '-2deg' }, { rotate: '0deg' }], { duration: 600 }),

    // A soft one-time shake (the cross on a wrong answer).
    shake: (el) => run(el, [{ translate: '0 0' }, { translate: '-6px 0' }, { translate: '6px 0' }, { translate: '-3px 0' }, { translate: '0 0' }], { duration: 450 }),

    // A press squish (the big Ready button).
    squish: (el) => run(el, [{ scale: 1 }, { scale: 0.94 }, { scale: 1.03 }, { scale: 1 }], { duration: 300 }),
};
