<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Js;

/** Render a Blade snippet in the given locale. */
function lamma(string $blade, array $data = [], string $locale = 'en'): string
{
    app()->setLocale($locale);

    return Blade::render($blade, $data);
}

const SHAPES = [
    0 => 'M9 2 L16.5 15.5 H1.5 Z',
    1 => '<circle cx="9" cy="9" r="7.5"',
    2 => '<rect x="2" y="2" width="14" height="14" rx="2"',
    3 => 'M9 1 L17 9 L9 17 L1 9 Z',
];

dataset('locales', ['en', 'ar']);

describe('logo', function () {
    it('renders every size in both locales', function (string $size, int $mark, string $locale) {
        $html = lamma('<x-lamma.logo :size="$size" />', compact('size'), $locale);

        expect($html)
            ->toContain('brand/lamma-icon.svg')
            ->toContain('width="'.$mark.'"')
            ->toContain($locale === 'ar' ? 'لمّة' : 'Lamma')
            ->toContain('wordmark')
            ->toContain($locale === 'ar' ? 'text-coral' : 'text-cream');
    })->with([['sm', 34], ['md', 48], ['lg', 64]])->with('locales');

    it('is a plain cream wordmark with no outline on navy', function (string $locale) {
        $html = lamma('<x-lamma.logo on-dark />', [], $locale);

        expect($html)->toContain('text-cream')->not->toContain('wordmark')->not->toContain('text-coral');
    })->with('locales');

    it('can force a locale different from the page', function () {
        expect(lamma('<x-lamma.logo locale="ar" />'))->toContain('لمّة');
    });
});

describe('button', function () {
    it('renders each variant and size', function (string $variant, string $classes) {
        foreach (['md' => 'min-h-14', 'lg' => 'min-h-16'] as $size => $height) {
            $html = lamma('<x-lamma.button :variant="$variant" :size="$size">Go</x-lamma.button>', compact('variant', 'size'));

            expect($html)->toContain('<button')->toContain('type="button"')->toContain($classes);
            if ($variant !== 'ghost') {
                expect($html)->toContain($height);
            }
        }
    })->with([
        'primary' => ['primary', 'bg-coral'],
        'dark' => ['dark', 'bg-navy text-cream'],
        'outline' => ['outline', 'border-navy'],
        'ghost' => ['ghost', 'min-h-11'],
        'sun' => ['sun', 'bg-sun'],
    ]);

    it('puts primary buttons on the sticker shadow and presses them down', function () {
        expect(lamma('<x-lamma.button>Go</x-lamma.button>'))->toContain('sticker-press')->toContain('shadow-sticker');
        expect(lamma('<x-lamma.button on-dark>Go</x-lamma.button>'))->toContain('shadow-sticker-dark');
        expect(lamma('<x-lamma.button variant="dark">Go</x-lamma.button>'))->toContain('shadow-sticker-coral-sm');
    });

    it('uses a cream border for the outline variant on navy', function () {
        expect(lamma('<x-lamma.button variant="outline" on-dark>Go</x-lamma.button>'))->toContain('border-cream')->toContain('text-cream');
    });

    it('renders as a link with an href and as a submit button with a type', function () {
        expect(lamma('<x-lamma.button href="/rooms/create">Host</x-lamma.button>'))->toContain('<a')->toContain('href="/rooms/create"');
        expect(lamma('<x-lamma.button type="submit">Save</x-lamma.button>'))->toContain('type="submit"');
    });

    it('shows an icon, or a lock when disabled', function () {
        expect(lamma('<x-lamma.button icon="play">Go</x-lamma.button>'))->toContain('<svg');

        expect(lamma('<x-lamma.button disabled icon="play">Go</x-lamma.button>'))
            ->toContain('disabled')->toContain('border-dashed')->toContain('border-line-strong')->toContain('text-ink-subtle')
            ->toContain('M8 11V7a4 4 0 0 1 8 0v4')->not->toContain('sticker-press');
    });
});

describe('answer', function () {
    it('renders every colour, state, size and locale with its shape and a spoken name', function (int $index, string $state, string $size, string $locale) {
        $html = lamma('<x-lamma.answer :index="$index" text="Mars" :text-alt="$alt" :size="$size" :state="$state" />', [
            'index' => $index, 'state' => $state, 'size' => $size, 'alt' => $size === 'host' ? 'المريخ' : null,
        ], $locale);

        expect($html)
            ->toContain(SHAPES[$index])
            ->toContain('aria-label="'.__('Answer :letter: :text', ['letter' => chr(65 + $index), 'text' => 'Mars']).'"')
            ->toContain(['bg-coral', 'bg-teal', 'bg-sun', 'bg-navy text-cream'][$index]);

        if ($state === 'faded') {
            expect($html)->toContain('opacity-35');
        }
        if ($state === 'correct' && $size === 'host') {
            expect($html)->toContain(__('Correct'));
        }
    })->with([0, 1, 2, 3])->with(['default', 'selected', 'locked', 'correct', 'faded'])->with(['host', 'phone'])->with('locales');

    it('shows the other-language line only on the host tile', function () {
        expect(lamma('<x-lamma.answer text="Mars" text-alt="المريخ" />'))->toContain('lang="ar"')->toContain('dir="rtl"')->toContain('المريخ');
        expect(lamma('<x-lamma.answer text="Mars" />'))->not->toContain('lang="ar"');
        expect(lamma('<x-lamma.answer size="phone" text="Mars" text-alt="المريخ" />'))->not->toContain('المريخ');
    });

    it('keeps the correct tile at full colour with the big sticker shadow and the faded ones dim', function () {
        expect(lamma('<x-lamma.answer :index="1" text="Mars" state="correct" />'))->toContain('shadow-sticker-lg')->not->toContain('opacity-35');
        expect(lamma('<x-lamma.answer :index="3" text="Mars" state="correct" />'))->toContain('shadow-sticker-dark-lg');
        expect(lamma('<x-lamma.answer text="Mars" state="faded" />'))->toContain('opacity-35')->not->toContain('shadow-sticker-lg');
    });

    it('gives phone tiles the 44px shape well, 88px height and a press state', function () {
        expect(lamma('<x-lamma.answer size="phone" text="Mars" />'))
            ->toContain('size-11')->toContain('bg-white/35')->toContain('h-22')->toContain('sticker-press');
    });

    it('slots mini avatars in on the host tile', function () {
        $html = lamma('<x-lamma.answer text="Mars" state="correct"><x-slot:pickers><x-lamma.avatar name="Sara" :size="36" /></x-slot:pickers></x-lamma.answer>');

        expect($html)->toContain('>S<');
    });

    it('respects reduced motion on the entrance and the reveal', function () {
        expect(lamma('<x-lamma.answer text="Mars" />'))->toContain('motion-safe:animate-pop-in')->toContain('motion-reduce:animate-fade-in');
        expect(lamma('<x-lamma.answer text="Mars" state="correct" />'))->toContain('motion-safe:animate-reveal');
    });
});

describe('timers', function () {
    beforeEach(fn () => Carbon::setTestNow('2026-10-01 12:00:00'));
    afterEach(fn () => Carbon::setTestNow());

    it('counts down from an ISO ends_at in Alpine, with no server calls', function (string $component, string $sun, string $locale) {
        $endsAt = '2026-10-01T12:00:12.250Z';
        $html = lamma("<x-lamma.{$component} :ends-at=\"\$endsAt\" :seconds=\"20\" />", compact('endsAt'), $locale);

        expect($html)
            ->toContain('x-data="lammaTimer('.Carbon::parse($endsAt)->getTimestampMs().', '.now()->getTimestampMs().', 20,')
            ->toContain('role="timer"')->toContain('dir="ltr"')->toContain('aria-live="polite"')
            ->toContain('>13<')->toContain($sun)
            ->not->toContain('wire:poll')->not->toContain('$wire')->not->toContain('fetch(');
    })->with([['timer-ring', 'stroke-sun'], ['timer-bar', 'bg-sun']])->with('locales');

    it('turns coral under five seconds and sun above', function () {
        $at = fn (string $component, int $seconds) => lamma("<x-lamma.{$component} :ends-at=\"\$e\" />", ['e' => now()->addSeconds($seconds)]);

        expect($at('timer-ring', 4))->toContain('class="stroke-coral transition-colors"');
        expect($at('timer-ring', 15))->toContain('class="stroke-sun transition-colors"');
        expect($at('timer-bar', 4))->toContain('class="h-full bg-coral"');
        expect($at('timer-bar', 15))->toContain('class="h-full bg-sun"');
    });

    it('shows zero for a deadline in the past', function () {
        expect(lamma('<x-lamma.timer-ring :ends-at="$e" />', ['e' => now()->subSeconds(5)]))->toContain('>0<');
        expect(lamma('<x-lamma.timer-bar :ends-at="$e" />', ['e' => now()->subSeconds(5)]))->toContain('>0<')->toContain('width: 0%');
    });

    it('labels the ring and the screen-reader announcement in the page language', function (string $locale) {
        $html = lamma('<x-lamma.timer-ring :ends-at="$e" />', ['e' => now()->addSeconds(12)], $locale);

        expect($html)->toContain('>'.__('seconds').'<')->toContain((string) Js::from(__(':seconds seconds left')));
    })->with('locales');

    it('pulses only with motion allowed', function () {
        expect(lamma('<x-lamma.timer-ring :ends-at="$e" />', ['e' => now()->addSeconds(12)]))->toContain('motion-safe:animate-tick');
        expect(lamma('<x-lamma.timer-bar :ends-at="$e" />', ['e' => now()->addSeconds(12)]))->toContain('motion-safe:animate-tick');
    });
});

describe('room code', function () {
    it('renders the hero tiles upper-cased, spelled out and left-to-right in both locales', function (string $locale) {
        $html = lamma('<x-lamma.room-code code="k7mp" />', [], $locale);

        expect($html)->toContain('dir="ltr"')->toContain('aria-label="K 7 M P"')
            ->toContain('bg-coral')->toContain('bg-teal')->toContain('bg-sun')->toContain('bg-white')
            ->toContain('shadow-sticker-lg')->toContain('-rotate-3')->toContain('rotate-3')
            ->and(substr_count($html, 'aria-hidden="true"'))->toBe(4);
    })->with('locales');

    it('renders the chip with an optional label', function (string $locale) {
        $html = lamma('<x-lamma.room-code code="k7mp" size="chip">Room</x-lamma.room-code>', [], $locale);

        expect($html)->toContain('dir="ltr"')->toContain('K7MP')->toContain('tracking-[3px]')->toContain('Room')->toContain('rounded-chip');
    })->with('locales');
});

describe('avatar', function () {
    it('shows the first letter in a ringed circle at any size', function (string $name, string $letter) {
        foreach ([36, 48, 120] as $size) {
            expect(lamma('<x-lamma.avatar :name="$name" :size="$size" />', compact('name', 'size')))
                ->toContain('>'.$letter.'<')->toContain('border-3 border-navy')->toContain("width: {$size}px");
        }
    })->with([['Sara', 'S'], ['ali', 'A'], ['علي', 'ع']]);

    it('cycles the palette by player index and accepts a literal colour', function () {
        $palette = ['bg-tint-coral', 'bg-teal', 'bg-sun', 'bg-coral', 'bg-tint-teal', 'bg-tint-sun'];

        foreach ($palette as $i => $class) {
            expect(lamma('<x-lamma.avatar name="A" :color="$i" />', ['i' => $i]))->toContain($class);
        }
        expect(lamma('<x-lamma.avatar name="A" :color="6" />'))->toContain('bg-tint-coral');
        expect(lamma('<x-lamma.avatar name="A" color="bg-navy" />'))->toContain('bg-navy');
    });

    it('adds the teal answered badge only when checked', function () {
        expect(lamma('<x-lamma.avatar name="A" checked />'))->toContain('bg-teal')->toContain('M5 12.5l4.5 4.5L19 7.5');
        expect(lamma('<x-lamma.avatar name="A" />'))->not->toContain('M5 12.5l4.5 4.5L19 7.5');
    });
});

describe('status pill and player row', function () {
    it('shows ready with a check and not ready dashed, with the state in text', function (string $locale) {
        $ready = lamma('<x-lamma.status-pill ready />', [], $locale);
        $waiting = lamma('<x-lamma.status-pill />', [], $locale);

        expect($ready)->toContain(__('Ready'))->toContain('bg-tint-teal')->toContain('border-teal')->toContain('<svg');
        expect($waiting)->toContain(__('Not ready'))->toContain('border-dashed')->toContain('border-line-strong')->toContain('text-ink-subtle')->not->toContain('<svg');
    })->with('locales');

    it('renders host and phone rows with a language badge and a status pill', function (string $size, string $locale) {
        $player = ['nickname' => $locale === 'ar' ? 'سارة' : 'Sara', 'locale' => $locale, 'is_ready' => true];
        $html = lamma('<x-lamma.player-row :player="$player" :size="$size" />', compact('player', 'size'), $locale);

        expect($html)->toContain('<li')->toContain($player['nickname'])->toContain(__('Ready'))
            ->toContain($locale === 'ar' ? '>ع<' : '>EN<')
            ->toContain($size === 'host' ? 'h-19' : 'h-14');
    })->with(['host', 'phone'])->with('locales');

    it('marks the current player, and hides the badge and pill on request', function () {
        $player = ['nickname' => 'Ali', 'locale' => 'en', 'is_ready' => false];

        expect(lamma('<x-lamma.player-row :player="$player" you />', compact('player')))->toContain('(you)')->toContain(__('Not ready'));
        expect(lamma('<x-lamma.player-row :player="$player" :show-language="false" :show-ready="false" />', compact('player')))
            ->not->toContain('>EN<')->not->toContain(__('Not ready'))->not->toContain('(you)');
    });

    it('accepts a model-like object too and fades in without motion', function () {
        $player = (object) ['nickname' => 'Maya', 'locale' => 'en', 'is_ready' => false];

        expect(lamma('<x-lamma.player-row :player="$player" />', compact('player')))
            ->toContain('Maya')->toContain('motion-safe:animate-row-in')->toContain('motion-reduce:animate-fade-in');
    });
});

describe('segmented', function () {
    it('marks the selected option with aria-pressed and the navy look', function (string $locale) {
        $options = ['en' => 'English', 'ar' => ['label' => 'العربية', 'lang' => 'ar'], 'both' => 'Both'];
        $html = lamma('<x-lamma.segmented label="Language" :options="$options" selected="both" />', compact('options'), $locale);

        expect($html)->toContain('aria-label="Language"')->toContain('lang="ar"')->toContain('العربية')
            ->toContain('x-bind:aria-pressed')->toContain('bg-navy font-bold text-cream')->toContain('h-12');
    })->with('locales');
});

describe('category toggle', function () {
    it('renders on and off states in both locales with an icon from the slug', function (bool $selected, string $locale) {
        $category = ['slug' => 'science', 'name' => $locale === 'ar' ? 'علوم' : 'Science'];
        $html = lamma('<x-lamma.category-toggle :category="$category" :selected="$selected" />', compact('category', 'selected'), $locale);

        expect($html)->toContain($category['name'])->toContain('aria-pressed="'.($selected ? 'true' : 'false').'"')
            ->toContain('M9 3h6M10 3v6') // flask icon
            ->toContain('h-18');

        if ($selected) {
            expect($html)->toContain('sticker-sm')->toContain('bg-navy text-cream')->toContain('M5 12.5l4.5 4.5L19 7.5');
        } else {
            expect($html)->toContain('border-2 border-line bg-white')->toContain('border-line-strong')->not->toContain('sticker-sm');
        }
    })->with([true, false])->with('locales');

    it('falls back to the sparkles icon for an unknown slug', function () {
        expect(lamma('<x-lamma.category-toggle :category="$c" />', ['c' => ['slug' => 'brand-new', 'name' => 'New']]))->toContain('M12 3l1.9 5.1');
    });
});

describe('chip, confetti, icon, shape', function () {
    it('renders every chip tone and size', function (string $tone, string $class) {
        foreach (['sm' => 'h-[34px]', 'md' => 'h-9', 'lg' => 'h-10'] as $size => $height) {
            expect(lamma('<x-lamma.chip :tone="$tone" :size="$size">Hi</x-lamma.chip>', compact('tone', 'size')))
                ->toContain($class)->toContain($height)->toContain('rounded-chip')->not->toContain('sticker');
        }
    })->with([['white', 'border-2 border-line'], ['coral', 'bg-tint-coral'], ['sun', 'bg-tint-sun'], ['teal', 'bg-tint-teal'], ['navy', 'bg-tint-navy'], ['line', 'bg-line']]);

    it('renders a chip icon', function () {
        expect(lamma('<x-lamma.chip icon="flask">Science</x-lamma.chip>'))->toContain('<svg');
    });

    it('scatters decorative confetti that mirrors in RTL', function (string $locale) {
        $html = lamma('<x-lamma.confetti :count="5" />', [], $locale);

        expect($html)->toContain('aria-hidden="true"')->toContain('inset-inline-start')->toContain('rtl:rotate-[calc(var(--r)*-1)]')
            ->and(substr_count($html, '<svg'))->toBe(5);
        expect(lamma('<x-lamma.confetti />', [], $locale))->toContain('pointer-events-none');
    })->with('locales');

    it('draws each answer shape and falls back for unknown icons', function () {
        foreach (SHAPES as $i => $marker) {
            expect(lamma('<x-lamma.shape :index="$i" />', ['i' => $i]))->toContain($marker)->toContain('aria-hidden="true"');
        }
        expect(lamma('<x-lamma.icon name="nope" />'))->toContain('<svg')->toContain('aria-hidden="true"');
    });
});

describe('conventions', function () {
    $files = fn () => collect([
        ...glob(resource_path('views/components/lamma/*.blade.php')),
        resource_path('views/layouts/lamma.blade.php'),
        resource_path('views/layouts/auth.blade.php'),
        resource_path('views/placeholder.blade.php'),
        ...glob(resource_path('views/components/auth-*.blade.php')),
        resource_path('views/components/passkey-verify.blade.php'),
        ...glob(resource_path('views/pages/auth/*.blade.php')),
        resource_path('views/landing.blade.php'),
        resource_path('views/dev/components.blade.php'),
    ])->filter(fn ($file) => is_file($file));

    // file => first offending snippet, so a failure names the culprit
    $scan = fn (string $pattern, ?callable $clean = null) => $files()
        ->mapWithKeys(fn ($file) => [basename($file) => preg_match($pattern, $clean ? $clean(file_get_contents($file)) : file_get_contents($file), $m) ? $m[0] : null])
        ->filter()->all();

    it('uses tokens, never raw hex colours, in Lamma views', function () use ($scan) {
        expect($scan('/#[0-9a-fA-F]{6}\b/', fn ($s) => preg_replace('/&#\d+;/', '', $s)))->toBe([]);
    });

    it('uses logical Tailwind utilities, never left/right ones', function () use ($scan) {
        $physical = '/(?<![\w:-])(?:ml|mr|pl|pr)-(?:\d|\[|px|auto)|(?<![\w-])(?:left|right)-(?:\d|\[|px|auto|full)|(?<![\w-])text-(?:left|right)\b|(?<![\w-])border-[lr](?:-\d|\s|")|(?<![\w-])rounded-(?:[lr]|[tb][lr])(?:-|\s|")/';

        expect($scan($physical))->toBe([]);
    });

    it('wraps room codes and timers in ltr', function () {
        expect(lamma('<x-lamma.room-code code="ABCD" />'))->toContain('dir="ltr"');
        expect(lamma('<x-lamma.timer-ring :ends-at="$e" />', ['e' => now()->addSeconds(5)]))->toContain('dir="ltr"');
        expect(lamma('<x-lamma.timer-bar :ends-at="$e" />', ['e' => now()->addSeconds(5)]))->toContain('dir="ltr"');
    });
});
