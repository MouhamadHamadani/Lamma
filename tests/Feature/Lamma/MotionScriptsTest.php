<?php

/*
 * The small scripts behind the game feel (resources/js/confetti.js, fx.js) and the keyframes in app.css: what they promise about reduced motion,
 * right-to-left pages, hidden tabs, the particle cap and performance. The scripts themselves run in a real browser in tests/Browser/MotionTest.php.
 */

describe('confetti.js', function () {
    beforeEach(fn () => $this->js = file_get_contents(resource_path('js/confetti.js')));

    it('offers burst(origin, count) and rain(duration), on window.lammaConfetti', function () {
        expect($this->js)->toContain('window.lammaConfetti = {')->toContain('burst(origin, count = 24')->toContain('rain(ms = 6000');
    });

    it('draws on one canvas that ignores the pointer, is hidden from screen readers and is removed after the last piece lands', function () {
        expect($this->js)->toContain("canvas.setAttribute('aria-hidden', 'true')")->toContain('pointer-events-none fixed inset-0')->toContain('canvas?.remove()')
            ->toContain('else stop();');
    });

    it('does nothing under reduced motion, nor while the tab is hidden, and drops everything when it hides', function () {
        expect($this->js)->toContain('if (reduced() || document.hidden) return false;')->toContain("document.addEventListener('visibilitychange'")->toContain('if (document.hidden) stop();');
    });

    it('never has more than 120 pieces on screen', function () {
        expect($this->js)->toContain('const CAP = 120;')->toContain('Math.min(count, CAP - pieces.length)')->toContain('pieces.length < CAP');
    });

    it('mirrors origins and drift in right-to-left pages', function () {
        expect($this->js)->toContain("document.documentElement.dir === 'rtl'")->toContain('(dir === 1 ? origin.x : 1 - origin.x)')->toContain("origin === 'start'")->toContain("origin === 'end'");
    });

    it('draws the four answer shapes in the brand colours read from the tokens, not from hex', function () {
        expect($this->js)->toContain("['triangle', 'circle', 'square', 'diamond']")->toContain("['coral', 'teal', 'sun', 'navy']")->toContain('--color-${name}')
            ->not->toMatch('/#[0-9a-fA-F]{3,6}\b/');
    });
});

describe('fx.js', function () {
    it('turns every flourish into a plain fade under reduced motion', function () {
        $js = file_get_contents(resource_path('js/fx.js'));

        expect($js)->toContain('prefers-reduced-motion: reduce')->toContain('reduced() ? [{ opacity: 0.5 }, { opacity: 1 }]');
        foreach (['pop', 'wobble', 'shake', 'squish'] as $name) {
            expect($js)->toContain("{$name}:");
        }
    });
});

describe('the keyframes (resources/css/app.css)', function () {
    it('move only transform properties and opacity (plus a stroke offset for the drawn check): no layout, so the host stays smooth', function () {
        $css = file_get_contents(resource_path('css/app.css'));
        $game = substr($css, strpos($css, 'Game feel (sound-and-motion)'));
        $game = substr($game, 0, strpos($game, "\n}\n"));
        preg_match_all('/@keyframes ([a-z-]+)\s*\{(.*?)\}\s*(?=@keyframes|\/\*|$)/s', $game, $frames, PREG_SET_ORDER);

        expect($frames)->not->toBeEmpty();
        foreach ($frames as [, $name, $body]) {
            preg_match_all('/(?<![a-z-])([a-z-]+)\s*:/', preg_replace('/[a-z-]+\([^)]*\)/', '', $body), $properties);
            $used = array_unique(array_filter($properties[1], fn (string $property) => ! in_array($property, ['from', 'to'], true)));

            expect(array_diff($used, ['opacity', 'scale', 'translate', 'rotate', 'stroke-dashoffset']))->toBe([], "keyframes {$name}");
        }
    });

    it('has a motion-safe use for every game-feel animation, and no screen animates a width, height, margin or top', function () {
        $views = collect(File::allFiles(resource_path('views')))->filter(fn ($file) => str_ends_with($file->getFilename(), '.blade.php'))->map(fn ($file) => file_get_contents($file->getPathname()))->implode("\n");

        foreach (['spring-in', 'tile-bounce', 'ring-pulse', 'ring-shake', 'bar-pulse', 'slide-in', 'settle', 'wipe-in', 'draw', 'shake', 'tile-drop', 'own-row', 'name-in', 'arrow-bob'] as $name) {
            expect($views)->toContain("motion-safe:animate-{$name}");
        }
        expect(file_get_contents(resource_path('css/app.css')))->not->toMatch('/@keyframes[^{]+\{[^}]*(width|height|margin|top|left)\s*:/');
    });
});

describe('the build', function () {
    it('uses no animation or confetti library: Alpine, CSS and the Web Animations API only', function () {
        $package = json_decode(file_get_contents(base_path('package.json')), true);
        $dependencies = array_keys([...($package['dependencies'] ?? []), ...($package['devDependencies'] ?? [])]);

        expect(preg_grep('/gsap|anime|framer|motion|lottie|confetti|howler|tone|popmotion|velocity|aos/i', $dependencies))->toBe([]);
    });

    it('imports each script of the game feel once, in the app bundle', function () {
        $app = file_get_contents(resource_path('js/app.js'));

        foreach (['sound', 'fx', 'confetti'] as $script) {
            expect(substr_count($app, "import './{$script}'"))->toBe(1);
        }
    });
});
