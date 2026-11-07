<?php

use App\Enums\HostScreenLocale;
use App\Game\GameEngine;
use App\Game\QuestionImage;
use App\Models\Question;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Browser\Support\ForgetGuards;

/*
 * The new question types in real browsers (Chromium): a closest-number round with a host screen and a phone in separate contexts (type a number with Arabic
 * digits, lock it, watch the number line and the ranked guesses), and picture rounds on the host at 1280x720 and 1920x1080 (nothing scrolls, the picture sits
 * above the options) and on phones (the tiles keep their 72px tap targets). Reverb is not running: the screens follow by polling, as in FullGameTest.
 * The pictures are real files: the disk points at a folder under public/ so the test web server serves them, and every picture must actually load.
 *
 * Run:  timeout 400 vendor/bin/pest tests/Browser/QuestionTypesTest.php   (LAMMA_SHOTS=1 saves screenshots in tests/Browser/Screenshots)
 */

// (ForgetGuards is added only by the test that plays several people: it would also drop actingAs() in the others, see tests/Browser/Support/ForgetGuards.)
beforeEach(function () {
    Queue::fake();
    File::deleteDirectory(public_path('test-question-images'));
    Storage::set(QuestionImage::DISK, Storage::build(['driver' => 'local', 'root' => public_path('test-question-images'), 'url' => '/test-question-images', 'visibility' => 'public']));
});

afterEach(fn () => File::deleteDirectory(public_path('test-question-images')));

function qtShot($page, string $name): void
{
    if (getenv('LAMMA_SHOTS')) {
        $page->screenshot(fullPage: false, filename: $name);
    }
}

function qtWait($page, string $expression, int $seconds = 25, string $what = ''): void
{
    if ($file = getenv('LAMMA_TRACE')) {
        file_put_contents($file, date('H:i:s').' waiting: '.($what ?: $expression).PHP_EOL, FILE_APPEND);
    }

    for ($i = 0; $i < $seconds * 2; $i++) {
        if ($page->script("() => Boolean({$expression})") === true) {
            return;
        }
        $page->wait(0.5);
    }

    throw new RuntimeException("Timed out after {$seconds}s waiting for: ".($what ?: $expression));
}

function qtNoScroll($page): void
{
    $page->assertScript('document.documentElement.scrollHeight <= window.innerHeight + 1')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth + 1')
        ->assertScript('document.body.scrollHeight <= window.innerHeight + 1');
}

/** The picture really loaded (not just an empty frame), and it sits above the first tile. */
function qtPictureAboveOptions(): string
{
    return "(() => { const image = document.querySelector('[data-test=question-image] img'); const tile = document.querySelector('[data-option], [data-test=answers] button');
        return image && image.complete && image.naturalWidth > 0 && image.getBoundingClientRect().bottom <= tile.getBoundingClientRect().top + 1; })()";
}

/**
 * A game of one question of this type, with a real picture when asked, 8 players, the host's screen on Both. The question is a realistic one (about 60 characters
 * in each language) or, with $long, an extreme one of 120 that fills four lines: the screen must still not scroll or overlap, but something at the very bottom may be cut.
 */
function qtGame(string $type, bool $picture, int $players = 8, array $settings = [], bool $long = false): array
{
    $host = User::factory()->create(['preferred_locale' => 'en']);
    $room = typedRoom([$type], players: $players, settings: ['secondsPerQuestion' => 60, 'doubleFinal' => false, 'hostScreenLocale' => HostScreenLocale::Both, ...$settings], host: $host, number: 1969, tolerance: 10);
    $question = Question::query()->latest('id')->first();
    $question->update([
        'text' => $long ? [
            'ar' => 'في أي عام هبطت أول مركبة فضائية مأهولة على سطح القمر في إطار برنامج أبولو الشهير؟',
            'en' => 'In which year did the first crewed spacecraft land on the surface of the Moon, as part of the famous Apollo programme?',
        ] : [
            'ar' => 'في أي عام هبطت أول مركبة مأهولة على سطح القمر؟',
            'en' => 'In which year did the first crewed spacecraft land on the Moon?',
        ],
        'explanation' => ['ar' => 'هبط نيل أرمسترونغ وباز ألدرين على القمر بينما بقي مايكل كولينز في المدار.', 'en' => 'Neil Armstrong and Buzz Aldrin walked on the Moon while Michael Collins stayed in orbit.'],
        ...($picture ? ['image_path' => app(QuestionImage::class)->store(jpegBytes(640, 480))] : []),
    ]);
    foreach ($room->players()->orderBy('id')->get() as $i => $player) {
        $player->update(['nickname' => $i % 2 ? "مريم الزهراء {$i}" : "Maximiliano {$i}", 'locale' => $i % 2 ? 'ar' : 'en']);
    }

    return [$host, $room->fresh()];
}

it('plays a closest-number round: a number typed in Arabic digits, the host waits, then the number line and the ranked guesses', function () {
    app(Kernel::class)->prependMiddleware(ForgetGuards::class);
    $host = User::factory()->create(['name' => 'Hadi Host', 'email' => 'host@lamma.test', 'preferred_locale' => 'en']);
    $room = typedRoom(['closest_number'], players: 0, settings: ['secondsPerQuestion' => 60, 'doubleFinal' => false, 'hostScreenLocale' => HostScreenLocale::Both], host: $host);
    $code = $room->code;

    $hostScreen = visit('/login')->withLocale('en')->resize(1280, 720);
    $hostScreen->type('[name=email]', $host->email)->type('[name=password]', 'password')->click('[data-test=login-button]');
    qtWait($hostScreen, "location.pathname === '/me/games'", 15, 'the host login');

    $phone = visit("/join?code={$code}")->withLocale('en')->resize(390, 844);
    $phone->type('[data-test=nickname-input]', 'Sara')->click('[data-test=join-button]');
    qtWait($phone, "location.pathname === '/play/{$code}'", 15, 'the phone lobby');

    $room->players()->update(['left_at' => null, 'is_ready' => true, 'locale' => 'en']);
    $ali = RoomPlayer::factory()->for($room)->create(['nickname' => 'Ali', 'locale' => 'en', 'is_ready' => true, 'left_at' => null]);
    startGame($room);

    $hostScreen->navigate("/host/{$code}");
    qtWait($hostScreen, "document.querySelector('[data-test=number-wait]')", 15, 'the waiting panel on the host');
    $phone->navigate("/play/{$code}");
    qtWait($phone, "document.querySelector('[data-test=number-input]')", 15, 'the number box on the phone');

    // The host: no tiles, nobody's guess, who has guessed. It does not scroll, even with two languages and a long question.
    $hostScreen->assertSee('Type your guess on your phone')->assertSee('اكتب تخمينك على هاتفك')->assertSeeIn('[data-test=answered-count]', '0 of 2 guessed')
        ->assertScript("document.querySelectorAll('[data-option]').length === 0");
    qtNoScroll($hostScreen);
    qtShot($hostScreen, 'types-number-host-waiting');

    // The phone: a left-to-right decimal field; the button waits for a digit.
    $phone->assertScript("document.querySelector('[data-test=number-input]').getAttribute('inputmode') === 'decimal'")
        ->assertScript("document.querySelector('[data-test=number-input]').dir === 'ltr'")
        ->assertScript("document.querySelector('[data-test=number-lock]').disabled === true")
        ->assertScript("document.querySelectorAll('[data-test=answers] [data-option]').length === 0")
        ->assertNoJavaScriptErrors();
    qtShot($phone, 'types-number-phone-question');
    $phone->type('[data-test=number-input]', 'abc')->assertScript("document.querySelector('[data-test=number-lock]').disabled === true");

    // Arabic-Indic digits, as an Arabic keyboard types them: the server reads 1969.
    $phone->type('[data-test=number-input]', '١٩٦٩')->assertScript("document.querySelector('[data-test=number-lock]').disabled === false");
    $phone->click('[data-test=number-lock]');
    qtWait($phone, "document.querySelector('[data-phase=answered]')", 10, 'the guess to lock');
    $phone->assertSee('Guess locked in')->assertSeeIn('[data-test=locked-number]', '1969')->assertSeeIn('[data-test=answered-count]', '1 of 2 guessed');
    qtShot($phone, 'types-number-phone-locked');

    // The host's poll brings the count, and still not the number.
    qtWait($hostScreen, "document.querySelector('[data-test=answered-count]')?.textContent.includes('1 of 2 guessed')", 10, '1 of 2 guessed on the host');
    $hostScreen->assertScript("! document.body.textContent.includes('1969') || document.body.textContent.indexOf('1969') === -1");

    // The last guess reveals it at once.
    app(GameEngine::class)->submitAnswer($ali, '1960');
    qtWait($hostScreen, "document.querySelector('[data-test=number-line]')", 15, 'the number line on the host');
    $hostScreen->assertSeeIn('[data-test=correct-marker]', '1969')->assertScript("document.querySelectorAll('[data-test=guess-row]').length >= 2")
        ->assertScript("document.querySelectorAll('[data-test=pin]').length === 2")->assertSee('Closest')->assertSee('Exact')
        ->assertVisible('[data-test=fun-fact]')->assertSee('A fun fact.');
    qtNoScroll($hostScreen);
    qtShot($hostScreen, 'types-number-host-reveal');

    qtWait($phone, "document.querySelector('[data-test=reveal-correct]')", 15, 'the reveal on the phone');
    $phone->assertSee('Spot on!')->assertSeeIn('[data-test=points-breakdown]', '100 + 50 closest')->assertSeeIn('[data-test=correct-number]', '1969')->assertSeeIn('[data-test=my-guess]', '1969')
        ->assertVisible('[data-test=fun-fact]')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth + 1')
        ->assertNoJavaScriptErrors();
    qtShot($phone, 'types-number-phone-reveal');

    $hostScreen->assertNoJavaScriptErrors();
    $phone->wait(1)->assertNoAccessibilityIssues();
});

it('shows a picture above the options on the host, with nothing scrolling, from 1280x720 to 1920x1080', function (string $type, int $width, int $height) {
    [$host, $room] = qtGame($type, picture: true, long: true);
    $this->actingAs($host);
    startGame($room);

    $page = visit("/host/{$room->code}")->resize($width, $height);
    qtWait($page, "document.querySelector('[data-test=question-image]')", 15, 'the picture');
    qtWait($page, qtPictureAboveOptions(), 10, 'the picture to load above the first tile');
    qtNoScroll($page);
    qtShot($page, "types-picture-host-{$type}-{$width}");
    $page->assertScript("[...document.querySelectorAll('[data-option]')].every((tile) => tile.getBoundingClientRect().bottom <= window.innerHeight)");

    // The same question, answered: the picture stays, the fun fact joins it, and still nothing scrolls.
    app(GameEngine::class)->reveal($room, 1, force: true);
    qtWait($page, "document.querySelector('[data-correct=true]')", 15, 'the reveal');
    qtWait($page, "document.querySelector('[data-test=fun-fact]')", 5, 'the fun fact');
    $page->assertScript("document.querySelector('[data-test=question-image] img').naturalWidth > 0");
    qtNoScroll($page);
    qtShot($page, "types-picture-host-{$type}-{$width}-reveal");
    $page->assertNoJavaScriptErrors();
})->with(['multiple_choice', 'true_false'])->with([[1280, 720], [1920, 1080]]);

it('shows true/false tiles and a closest-number reveal on the host without scrolling', function (string $type, int $width, int $height) {
    [$host, $room] = qtGame($type, picture: false, players: 10);
    $this->actingAs($host);
    startGame($room);

    $page = visit("/host/{$room->code}")->resize($width, $height);
    qtWait($page, "document.querySelector('[data-test=question-text]')", 15, 'the question');
    qtNoScroll($page);

    foreach ($room->players()->orderBy('id')->get() as $i => $player) {
        $type === 'closest_number' ? guess($player, 1969 + ($i - 4) * 3) : answer($player, $room->currentQuestion(), $i % 3 !== 0);
    }
    qtWait($page, "document.querySelector('[data-test=fun-fact]')", 15, 'the reveal');
    // One look at the screen (the 5-second reveal is short): nothing scrolls, everything is on screen down to the "N of M" line, and the fun fact does not overlap it.
    $page->assertScript("(() => { const count = document.querySelector('[data-test=correct-count]').getBoundingClientRect(); const fact = document.querySelector('[data-test=fun-fact]').getBoundingClientRect();
        return document.documentElement.scrollHeight <= window.innerHeight + 1 && document.body.scrollHeight <= window.innerHeight + 1 && count.bottom <= window.innerHeight && fact.bottom <= count.top + 1; })()");
    qtShot($page, "types-{$type}-host-reveal-{$width}");
    $page->assertNoJavaScriptErrors();
})->with(['true_false', 'closest_number'])->with([[1280, 720], [1920, 1080]]);

it('keeps the tap targets at 72px under a picture and for true/false, on a normal and a small phone', function (string $type, int $width, int $height) {
    [, $room] = qtGame($type, picture: $type === 'multiple_choice', players: 1);
    $room->players()->update(['locale' => 'en']);

    $phone = visit("/join?code={$room->code}")->withLocale('en')->resize($width, $height);
    $phone->type('[data-test=nickname-input]', 'Sara')->click('[data-test=join-button]');
    qtWait($phone, "location.pathname === '/play/{$room->code}'", 15, 'the phone lobby');
    $room->players()->update(['left_at' => null, 'is_ready' => true]);
    startGame($room);
    $phone->navigate("/play/{$room->code}");
    qtWait($phone, "document.querySelector('[data-test=answers] button')", 15, 'the tiles');
    $phone->wait(1);

    $phone->assertScript("[...document.querySelectorAll('[data-test=answers] button')].every((tile) => tile.getBoundingClientRect().height >= 72)")
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth + 1');
    if ($type === 'multiple_choice') {
        $phone->assertScript(qtPictureAboveOptions());
    }
    qtShot($phone, "types-{$type}-phone-{$width}");
    $phone->assertNoJavaScriptErrors();
})->with(['multiple_choice', 'true_false'])->with([[390, 844], [360, 640]]);
