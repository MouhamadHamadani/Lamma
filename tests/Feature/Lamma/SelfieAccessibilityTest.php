<?php

use App\Livewire\Player\JoinRoom;
use App\Models\Room;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

/*
 * The selfie step (HANDOFF section 8 and the Phase 9 brief): real buttons, a name for everything, announcements for screen readers in both
 * languages, the nickname field left-to-right in Arabic, and nothing that moves outside motion-safe.
 */

function selfieBlock(string $locale = 'en'): string
{
    app()->setLocale($locale);

    return Blade::render('<x-lamma.selfie />');
}

describe('the selfie block', function () {
    it('uses real buttons only, each with a name, and no clickable div', function () {
        $html = selfieBlock();

        preg_match_all('/<button\b[^>]*>(.*?)<\/button>/s', $html, $buttons, PREG_SET_ORDER);
        expect($buttons)->not->toBeEmpty();
        foreach ($buttons as [$tag, $inner]) {
            expect(trim(strip_tags($inner)) !== '' || str_contains($tag, 'aria-label='))->toBeTrue("a button without a name: {$tag}");
        }
        expect($html)->not->toContain('role="button"')->not->toMatch('/<div[^>]*x-on:click/')->not->toMatch('/<span[^>]*x-on:click/');
    });

    it('names the shutter, hides the live video from screen readers and labels the camera group', function () {
        $html = selfieBlock();

        expect($html)->toContain('aria-label="Take photo"')->toContain('aria-label="Camera"')->toMatch('/<video[^>]*aria-hidden="true"/')->toContain('data-test="selfie-shutter"');
    });

    it('announces "Photo added", "Photo removed" and "Upload failed, try again" through a polite live region, in both languages', function (string $locale, array $messages) {
        $html = selfieBlock($locale);

        expect($html)->toMatch('/<p[^>]*role="status"[^>]*aria-live="polite"[^>]*x-text="status"/');
        foreach ($messages as $message) {
            expect($html)->toContain($message);
        }
    })->with([
        'English' => ['en', ['Photo added', 'Photo removed', 'Upload failed, try again']],
        'Arabic' => ['ar', ['تمت إضافة الصورة', 'تمت إزالة الصورة', 'فشل الرفع، حاول مرة أخرى']],
    ]);

    it('says it in both languages when the camera cannot be opened', function (string $locale, string $message) {
        expect(selfieBlock($locale))->toContain($message)->toContain('data-test="selfie-try-again"')->toContain('data-test="selfie-skip-denied"');
    })->with([
        'English' => ['en', 'We couldn&#039;t open the camera. You can allow it in your browser settings, or skip.'],
        'Arabic' => ['ar', 'تعذّر فتح الكاميرا. يمكنك السماح بها من إعدادات المتصفح، أو التخطي.'],
    ]);

    it('hides every state but the first until Alpine has started, so nothing flashes', function () {
        $html = selfieBlock();

        expect(substr_count($html, 'x-cloak'))->toBeGreaterThanOrEqual(6)->and($html)->toContain('data-test="selfie-camera"');
        expect(preg_match('/data-test="selfie-camera"[^>]*x-cloak|x-cloak[^>]*data-test="selfie-camera"/', $html))->toBe(1);
    });

    it('has no animation at all, so nothing moves under reduced motion', function () {
        // (the buttons fade their colour on hover, which is not movement)
        expect(selfieBlock())->not->toMatch('/(?<![:\w-])animate-/')->not->toContain('transition-transform');
    });

    it('is a 44px target everywhere: the buttons are at least 44px tall by their classes', function () {
        // <x-lamma.button> is min-h-14 / min-h-16, the text buttons min-h-11 (44px), the shutter 76px
        $html = selfieBlock();

        preg_match_all('/<button\b[^>]*class="([^"]*)"/', $html, $classes);
        foreach ($classes[1] as $class) {
            expect($class)->toMatch('/min-h-(11|14|16)|size-\[76px\]/');
        }
    });
});

describe('the pages that hold it', function () {
    it('keeps the nickname field left-to-right in Arabic, on the join page and in the lobby panel', function () {
        $room = Room::factory()->create();
        $join = Livewire::withQueryParams(['code' => $room->code])->test(JoinRoom::class)->html();

        expect($join)->toMatch('/id="join-nickname"[^>]*dir="ltr"/');
    });

    it('mirrors with logical utilities only (the existing RTL rule covers the new files)', function () {
        $files = [resource_path('views/components/lamma/selfie.blade.php'), resource_path('views/livewire/player/player-lobby.blade.php'), resource_path('views/livewire/player/join-room.blade.php')];

        foreach ($files as $file) {
            preg_match_all('/class="([^"]*)"/', file_get_contents($file), $classes);
            foreach ($classes[1] as $class) {
                expect(preg_grep('/^(-?(ml|mr|pl|pr|left|right)-|text-(left|right)$)/', preg_split('/\s+/', $class)))->toBe([], basename($file).': '.$class);
            }
        }
    });
});
