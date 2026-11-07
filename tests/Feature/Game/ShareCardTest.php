<?php

use App\Enums\HostScreenLocale;
use App\Events\GameStarted;
use App\Events\PlayerUpdated;
use App\Events\QuestionStarted;
use App\Game\Scoreboard;
use App\Game\ShareCard;
use App\Livewire\Host\HostResults;
use App\Livewire\Player\PlayerResults;
use App\Models\Room;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
 * "Share result": the data the page hands the browser (nothing private), the button on the phone results and the host podium in both languages, and the script
 * that draws the 1080x1350 PNG on a canvas. The drawing itself is checked in a real browser (tests/Browser/ShareCardTest.php).
 */

beforeEach(function () {
    Queue::fake();
    Event::fake([GameStarted::class, QuestionStarted::class, PlayerUpdated::class]);
    config(['app.url' => 'https://lamma.build-syntax.com']);
});

/** A finished game, English phones, with these players (nickname => score); the first one is the viewer on a phone. */
function sharedGame(array $scores = ['Ali' => 300, 'Sara' => 200, 'Hadi' => 100, 'Maya' => 50], array $settings = []): Room
{
    $room = finishedGame($scores, questions: 3, settings: $settings);
    $room->players()->update(['locale' => 'en']);

    return $room;
}

function shareFor(Room $room, string $surface = 'host', ?string $viewer = null): array
{
    $ranking = app(Scoreboard::class)->ranking($room);
    $myId = $viewer === null ? null : $room->players()->where('nickname', $viewer)->value('id');

    return app(ShareCard::class)->for($room, $ranking, $surface, $myId);
}

describe('what is on the card', function () {
    it('has the winner, the top three with places and scores, the brand and the address', function () {
        $card = shareFor(sharedGame());

        expect($card['headline'])->toBe("\u{2066}Ali\u{2069} wins!")->and($card['eyebrow'])->toBe('Game over · 3 questions')
            ->and($card['top'])->toBe([['rank' => 1, 'name' => 'Ali', 'score' => 300], ['rank' => 2, 'name' => 'Sara', 'score' => 200], ['rank' => 3, 'name' => 'Hadi', 'score' => 100]])
            ->and($card['brand'])->toBe('Lamma')->and($card['cta'])->toBe('The quiz night for everyone')->and($card['filename'])->toBe('lamma-result.png')
            ->and($card['me'])->toBeNull()->and($card['surface'])->toBe('host');
    });

    it('reads the address from the app URL, never a hard-coded one', function () {
        expect(shareFor(sharedGame())['host'])->toBe('lamma.build-syntax.com')->and(shareFor(sharedGame())['shareText'])->toBe('lamma.build-syntax.com');

        config(['app.url' => 'http://localhost:8010']);
        expect(shareFor(sharedGame())['host'])->toBe('localhost')->and(ShareCard::host())->toBe('localhost');

        config(['app.url' => 'https://quiz.example.org/some/path']);
        expect(ShareCard::host())->toBe('quiz.example.org');

        expect(file_get_contents(base_path('app/Game/ShareCard.php')))->not->toContain('lamma.build-syntax.com')->and(file_get_contents(resource_path('js/share-card.js')))->not->toContain('build-syntax');
    });

    it('adds the viewer\'s own place on a phone, in their language', function () {
        $card = shareFor(sharedGame(), 'phone', 'Sara');

        expect($card['me'])->toBe(['label' => 'Your place: 2nd'])->and($card['surface'])->toBe('phone');

        app()->setLocale('ar');
        $arabic = shareFor(sharedGame(), 'phone', 'Sara');
        expect($arabic['me'])->toBe(['label' => 'ترتيبك: 2'])->and($arabic['brand'])->toBe('لمّة')->and($arabic['locale'])->toBe('ar')->and($arabic['headline'])->toContain('الفوز من نصيب')
            ->and($arabic['eyebrow'])->toBe('انتهت اللعبة · عدد الأسئلة 3')->and($arabic['cta'])->toBe('سهرة الأسئلة للكل');
    });

    it('puts a player outside the top three on the card as their own band, not in the list', function () {
        $card = shareFor(sharedGame(), 'phone', 'Maya');

        expect($card['top'])->toHaveCount(3)->and(collect($card['top'])->pluck('name')->all())->not->toContain('Maya')->and($card['me']['label'])->toBe('Your place: 4th');
    });

    it('says what happened: a tie, two winners, nobody', function () {
        expect(shareFor(sharedGame(['Ali' => 300, 'Sara' => 300, 'Hadi' => 100]))['headline'])->toBe("\u{2066}Ali\u{2069} & \u{2066}Sara\u{2069} win!")
            ->and(shareFor(sharedGame(['Ali' => 300, 'Sara' => 300, 'Hadi' => 300]))['headline'])->toBe("It's a tie!")
            ->and(shareFor(sharedGame(['Ali' => 0, 'Sara' => 0]))['headline'])->toBe('No winner this time');
    });

    it('has fewer rows when there are fewer players', function () {
        expect(shareFor(sharedGame(['Ali' => 300, 'Sara' => 200]))['top'])->toHaveCount(2);
    });

    it('carries nothing private: no room code, no photo, no player id, no token, no account', function () {
        $room = sharedGame(settings: ['showPhotos' => true]);
        $room->players()->update(['avatar_path' => 'rooms/1/secret-photo.webp']);
        $json = json_encode(shareFor($room, 'phone', 'Sara'), JSON_UNESCAPED_UNICODE);

        expect($json)->not->toContain($room->code)->and($json)->not->toContain('avatar')->and($json)->not->toContain('secret-photo')->and($json)->not->toContain('guest_token')
            ->and($json)->not->toContain('user_id')->and($json)->not->toContain('"id"')->and($json)->not->toContain('player')
            ->and(array_keys(shareFor($room, 'phone', 'Sara')))->toBe(['locale', 'surface', 'brand', 'logo', 'eyebrow', 'headline', 'top', 'me', 'cta', 'host', 'filename', 'shareTitle', 'shareText'])
            ->and(array_keys(shareFor($room)['top'][0]))->toBe(['rank', 'name', 'score']);
    });

    it('uses the logo from the site itself, root-relative, so a phone on the laptop\'s address loads it', function () {
        expect(shareFor(sharedGame())['logo'])->toBe('/brand/lamma-logo-stacked-dark.svg')->and(public_path('brand/lamma-logo-stacked-dark.svg'))->toBeFile();
    });
});

describe('the button', function () {
    it('is on the phone results, with the viewer\'s place on the card, in both languages', function (string $locale, string $label, string $place) {
        $room = sharedGame();
        $room->players()->update(['locale' => $locale]);
        $viewer = $room->players()->where('nickname', 'Sara')->first();

        $page = Livewire::withCookie('lamma_guest', $viewer->guest_token)->test(PlayerResults::class, ['room' => $room]);

        $page->assertSee($label)->assertSee('data-test="share-button"', false)->assertSee('data-test="share-result"', false)->assertSee('lammaShare(', false)
            ->assertSee($place, false);
        expect($page->html())->not->toContain($room->code.'"')->and($page->html())->toContain('wire:ignore');
    })->with([
        'english' => ['en', 'Share result', 'Your place: 2nd'],
        'arabic' => ['ar', 'شارك النتيجة', 'ترتيبك: 2'],
    ]);

    it('is on the host podium, in both languages, without the viewer\'s place', function (HostScreenLocale $locale, string $label) {
        $room = sharedGame(settings: ['hostScreenLocale' => $locale]);

        $page = Livewire::actingAs($room->host)->test(HostResults::class, ['room' => $room]);

        $page->assertSee($label)->assertSee('data-test="share-result"', false)->assertSee('lammaShare(', false)->assertSee('\u0022surface\u0022:\u0022host\u0022', false)
            ->assertSee('\u0022me\u0022:null', false);
    })->with([
        'english' => [HostScreenLocale::En, 'Share result'],
        'arabic' => [HostScreenLocale::Ar, 'شارك النتيجة'],
    ]);

    it('is on the demo\'s end screens too, next to Host your own game', function () {
        $room = finishedDemo();

        $page = demoHostPage(HostResults::class, $room);

        $page->assertSee('data-test="share-result"', false)->assertSee('data-test="host-your-own"', false);
    });

    it('does not put the room code on the page inside the card', function () {
        $room = sharedGame();
        $page = Livewire::actingAs($room->host)->test(HostResults::class, ['room' => $room]);

        preg_match('/lammaShare\((.*?)\)"/s', $page->html(), $call);
        expect($call[0] ?? '')->not->toContain($room->code);
    });
});

describe('the script', function () {
    $script = fn () => file_get_contents(resource_path('js/share-card.js'));

    it('draws a 1080x1350 PNG on a canvas in the browser, not on the server', function () use ($script) {
        $source = $script();

        expect($source)->toContain('const WIDTH = 1080')->toContain('const HEIGHT = 1350')->toContain("'image/png'")->toContain("document.createElement('canvas')")
            ->and(file_exists(base_path('app/Game/ShareImage.php')))->toBeFalse();
    });

    it('waits for the display font before it draws', function () use ($script) {
        expect($script())->toContain('document.fonts.load')->toContain('Baloo Bhaijaan 2')->and(strpos($script(), 'document.fonts.load'))->toBeLessThan(strpos($script(), "getContext('2d')"));
    });

    it('reads the brand colours from the CSS tokens, with no raw hex', function () use ($script) {
        $source = $script();

        foreach (['--color-navy', '--color-cream', '--color-sun', '--color-teal', '--color-coral'] as $token) {
            expect($source)->toContain($token);
        }
        expect(preg_match('/#[0-9a-fA-F]{3,8}\b/', $source))->toBe(0);
    });

    it('shares the file where the browser can and downloads it everywhere else, and tells analytics which', function () use ($script) {
        $source = $script();

        expect($source)->toContain('navigator.canShare')->toContain('files: [file]')->toContain('navigator.share(')->toContain('link.download')
            ->toContain("lammaTrack?.('share_clicked'")->toContain("'AbortError'");
    });

    it('draws right to left for Arabic and keeps the address left to right', function () use ($script) {
        expect($script())->toContain("ctx.direction = rtl || ARABIC.test(value) ? 'rtl' : 'ltr'")->toContain('rtl: false });')->toContain("card.locale === 'ar'");
    });

    it('is loaded by app.js, and its Alpine component is registered', function () use ($script) {
        expect(file_get_contents(resource_path('js/app.js')))->toContain("import './share-card'")->and($script())->toContain("Alpine.data('lammaShare'");
    });

    it('has no animation of its own, so reduced motion has nothing to turn off, and the status only fades in motion-safe', function () use ($script) {
        expect($script())->not->toContain('requestAnimationFrame')->not->toContain('setInterval')
            ->and(file_get_contents(resource_path('views/components/lamma/share-button.blade.php')))->toContain('motion-safe:animate-fade-in');
    });
});
