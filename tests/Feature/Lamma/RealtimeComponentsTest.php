<?php

use Illuminate\Support\Facades\Blade;

function realtimeRender(string $blade, array $data = [], string $locale = 'en'): string
{
    app()->setLocale($locale);

    return Blade::render($blade, $data);
}

describe('dots', function () {
    it('are three pulsing dots with a 150ms stagger, hidden from screen readers', function () {
        $html = realtimeRender('<x-lamma.dots />');

        expect(substr_count($html, 'motion-safe:animate-dots'))->toBe(3)
            ->and($html)->toContain('animation-delay: 0ms')->toContain('animation-delay: 150ms')->toContain('animation-delay: 300ms')
            ->toContain('aria-hidden="true"');
    });

    it('only animate with motion allowed', function () {
        expect(realtimeRender('<x-lamma.dots />'))->not->toMatch('/(?<![\w:-])animate-dots/');
        expect(file_get_contents(resource_path('css/app.css')))->toContain('--animate-dots: dots 1.2s ease-in-out infinite')->toContain('@keyframes dots');
    });
});

describe('the reconnecting card', function () {
    it('says Reconnecting… with pulsing dots, centred over the page, in either language', function (string $locale, string $text) {
        $html = realtimeRender('<x-lamma.reconnecting />', [], $locale);

        expect($html)->toContain($text)->toContain('x-data="lammaConnection"')->toContain('x-show="offline"')->toContain('x-cloak')->toContain('wire:ignore')
            ->toContain('role="alert"')->toContain('fixed inset-0')->toContain('items-center justify-center')
            ->toContain('motion-safe:animate-dots');
    })->with([['en', 'Reconnecting…'], ['ar', 'جارٍ إعادة الاتصال…']]);

    it('has its Alpine logic: hidden until the connection is lost after being up', function () {
        $js = file_get_contents(resource_path('js/lamma.js'));

        expect($js)->toContain("Alpine.data('lammaConnection'")->toContain("'state_change'")
            ->toContain("state === 'connected'")->toContain("['unavailable', 'failed', 'disconnected']")
            ->toContain("state === 'connecting' && this.wasConnected");
    });
});

describe('Echo', function () {
    it('only connects on real-time pages', function () {
        $js = file_get_contents(resource_path('js/echo.js'));

        expect($js)->toContain("document.documentElement.hasAttribute('data-realtime')");
    });

    it('reaches Reverb on the laptop\'s own address from a phone, unless a real host is configured', function () {
        $js = file_get_contents(resource_path('js/echo.js'));

        expect($js)->toContain("['', 'localhost', '127.0.0.1', '[::1]']")->toContain('window.location.hostname')->toContain('wsHost: host');
    });

    it('sets data-realtime on <html> only when the layout asks for it', function () {
        expect(realtimeRender('<x-layouts::lamma :realtime="true">x</x-layouts::lamma>'))->toMatch('/<html[^>]* data-realtime/');
        expect(realtimeRender('<x-layouts::lamma>x</x-layouts::lamma>'))->not->toContain('data-realtime');
    });

    it('puts the CSRF token in every page head, for the channel auth request', function () {
        expect($this->get('/')->getContent())->toMatch('/<meta name="csrf-token" content="[^"]{20,}"/');
    });
});

describe('notice', function () {
    it('shows a one-time message from the session as a status', function () {
        session()->put('notice', 'The host closed this room.');

        expect(realtimeRender('<x-lamma.notice />'))->toContain('The host closed this room.')->toContain('role="status"')->toContain('data-test="notice"');
    });

    it('renders nothing without a message', function () {
        expect(trim(realtimeRender('<x-lamma.notice />')))->toBe('');
    });
});

describe('a disconnected player', function () {
    it('shows a dashed Disconnected pill, never Ready', function () {
        $html = realtimeRender('<x-lamma.status-pill ready disconnected />');

        expect($html)->toContain('Disconnected')->toContain('border-dashed')->not->toContain('Ready')->not->toContain('bg-tint-teal');
    });

    it('greys out the row but keeps the name readable and the pill', function () {
        $html = realtimeRender('<x-lamma.player-row :player="$p" />', ['p' => ['nickname' => 'Maya', 'locale' => 'en', 'is_ready' => true, 'left_at' => now()]]);

        expect($html)->toContain('data-disconnected')->toContain('opacity-45')->toContain('Maya')->toContain('Disconnected');
    });

    it('is a normal row when connected', function () {
        $html = realtimeRender('<x-lamma.player-row :player="$p" />', ['p' => ['nickname' => 'Maya', 'locale' => 'en', 'is_ready' => true, 'left_at' => null]]);

        expect($html)->not->toContain('data-disconnected')->not->toContain('opacity-45')->toContain('Ready');
    });

    it('takes an actions slot at the end of the row', function () {
        $html = realtimeRender('<x-lamma.player-row :player="$p"><x-slot:actions><button data-x="remove">x</button></x-slot:actions></x-lamma.player-row>', ['p' => ['nickname' => 'Maya', 'locale' => 'en', 'is_ready' => false]]);

        expect($html)->toContain('data-x="remove"')->and(strpos($html, 'data-x="remove"'))->toBeGreaterThan(strpos($html, 'Not ready'));
    });
});
