<?php

use App\Enums\HostScreenLocale;
use App\Game\GameEngine;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Queue;
use Tests\Browser\Support\ForgetGuards;

/*
 * Vote mode in real browsers (Chromium): one vote round with a host screen and a phone in separate contexts. The host sees the counts change, the phone
 * locks its vote with a real tap, the result shows on both, and the next question follows. Reverb is not running (the screens follow by polling, as in
 * FullGameTest): the vote is opened in the database and the second player votes there.
 */

// Two people at once: every request must find out who it is from its own cookie (see ForgetGuards).
beforeEach(function () {
    app(Kernel::class)->prependMiddleware(ForgetGuards::class);
    Queue::fake();
    // Time for the clicks and the polls; the rule itself (15 s) is tested in tests/Feature/Game/VoteTest.php.
    config(['lamma.vote_seconds' => 40]);
});

function vbTrace(string $line): void
{
    if ($file = getenv('LAMMA_TRACE')) {
        file_put_contents($file, date('H:i:s').' '.$line.PHP_EOL, FILE_APPEND);
    }
}

function vbWait($page, string $expression, int $seconds = 25, string $what = ''): void
{
    // LAMMA_TRACE=<file> logs where a run is waiting (a stuck run shows where it stopped).
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

function vbNoScroll($page): void
{
    $page->assertScript('document.documentElement.scrollHeight <= window.innerHeight + 1')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth + 1');
}

it('plays one vote round: live counts on the host, a locked vote on the phone, the result on both, then the next question', function () {
    $host = User::factory()->create(['name' => 'Hadi Host', 'email' => 'host@lamma.test', 'preferred_locale' => 'en']);
    $categories = [
        categoryWithQuestions(6, category: ['name' => ['en' => 'Opening Act', 'ar' => 'الافتتاح']]),
        categoryWithQuestions(6, category: ['name' => ['en' => 'Planets', 'ar' => 'الكواكب']]),
        categoryWithQuestions(6, category: ['name' => ['en' => 'Rivers', 'ar' => 'الأنهار']]),
    ];
    $room = voteRoom(players: 0, questions: 10, every: 5, categories: $categories, settings: ['hostScreenLocale' => HostScreenLocale::Both], host: $host);
    $code = $room->code;

    $hostScreen = visit('/login')->withLocale('en')->resize(1280, 720);
    $hostScreen->type('[name=email]', $host->email)->type('[name=password]', 'password')->click('[data-test=login-button]');
    vbWait($hostScreen, "location.pathname === '/me/games'", 15, 'the host login');

    $phone = visit("/join?code={$code}")->withLocale('en')->resize(390, 844);
    $phone->type('[data-test=nickname-input]', 'Sara')->click('[data-test=join-button]');
    vbWait($phone, "location.pathname === '/play/{$code}'", 15, 'the phone lobby');

    // What the presence connection would do; then a second player, and the game played to its first vote in the database.
    $room->players()->update(['left_at' => null, 'is_ready' => true, 'locale' => 'en']);
    $ali = RoomPlayer::factory()->for($room)->create(['nickname' => 'Ali', 'locale' => 'en', 'is_ready' => true, 'left_at' => null]);
    $round = playToVote($room);

    $hostScreen->navigate("/host/{$code}");
    vbWait($hostScreen, "document.querySelector('[data-vote=open]')", 15, 'the vote on the host');
    $phone->navigate("/play/{$code}");
    vbWait($phone, "document.querySelector('[data-vote=vote]')", 15, 'the vote on the phone');

    $hostScreen->assertSee('Choose the next category')->assertSee('اختر الفئة التالية')->assertSeeIn('[data-test=voted-count]', '0 of 2 voted');
    vbNoScroll($hostScreen);
    $phone->assertSee('Choose the next category')->assertScript("document.querySelectorAll('[data-test=vote-options] button').length === 2");

    // No call per second while it counts down: the timers run in the browser, only the 2-second poll and the one tick at the deadline remain.
    $hostScreen->script("() => { window.__lwCalls = 0; const send = window.fetch; window.fetch = (...args) => { if (String(args[0]).includes('/update')) window.__lwCalls++; return send(...args); }; }");
    $hostScreen->wait(4);
    expect($hostScreen->script('() => window.__lwCalls'))->toBeLessThanOrEqual(3);

    // A real tap on the phone locks the vote.
    $phone->click('[data-test=vote-options] button[data-category="'.$round->option_ids[1].'"]');
    vbWait($phone, "document.querySelector('[data-vote=voted]')", 10, 'the vote to lock');
    $phone->assertSee('Vote locked in')->assertSeeIn('[data-test=voted-count]', '1 of 2 voted');

    // The host's poll brings the count.
    vbWait($hostScreen, "document.querySelector('[data-category=\"{$round->option_ids[1]}\"] [data-test=vote-count]')?.textContent.trim() === '1'", 10, 'the count on the host');
    $hostScreen->assertSeeIn('[data-test=voted-count]', '1 of 2 voted');

    // The last vote closes it at once: the result on both screens.
    app(GameEngine::class)->castVote($ali, $round->option_ids[1]);
    vbWait($hostScreen, "document.querySelector('[data-vote=result]')", 10, 'the result on the host');
    $hostScreen->assertSee('The next category is')->assertSee('Winner')->assertScript("document.querySelector('[data-winner=true]').dataset.category === '{$round->option_ids[1]}'");
    vbNoScroll($hostScreen);
    vbWait($phone, "document.querySelector('[data-test=vote-result]')", 10, 'the result on the phone');
    $phone->assertSee('Your pick won!');

    // After the result's 3 seconds the host screen's tick opens question 6, from the winner.
    vbWait($hostScreen, "document.querySelector('[data-test=question-text]')", 15, 'question 6 on the host');
    $hostScreen->assertSeeIn('[data-test=progress-label]', 'Question 6 of 10');
    expect(topCategoryOf($room->fresh()->currentQuestion()))->toBe($round->option_ids[1]);

    $hostScreen->assertNoJavaScriptErrors();
    $phone->assertNoJavaScriptErrors();
});

it('does not scroll on the create-room Vote setup, nor on the vote and its result, from 1280x720 to 1920x1080', function (int $width, int $height) {
    $host = User::factory()->create(['email' => 'host@lamma.test', 'preferred_locale' => 'en']);
    $room = voteRoom(players: 3, questions: 10, every: 5, host: $host, settings: ['hostScreenLocale' => HostScreenLocale::Both]);

    // A real login: ForgetGuards (on for this file) would drop an actingAs().
    $page = visit('/login')->withLocale('en')->resize($width, $height);
    $page->type('[name=email]', $host->email)->type('[name=password]', 'password')->click('[data-test=login-button]');
    vbWait($page, "location.pathname === '/me/games'", 15, 'the host login');
    $page->navigate('/rooms/create');
    vbWait($page, "document.querySelector('[data-test=mode]')", 15, 'the create-room page');
    $page->click('[data-test=mode] button:nth-child(2)');
    vbWait($page, "document.querySelector('[data-test=vote-setup]')", 10, 'the Vote setup');
    $page->click('[data-test="first-'.$room->firstCategory->slug.'"]');
    vbWait($page, "document.querySelector('[data-test=vote-summary]')", 10, 'the vote summary');
    vbNoScroll($page);
    $page->assertNoJavaScriptErrors();

    $round = playToVote($room);
    $page->navigate("/host/{$room->code}");
    vbWait($page, "document.querySelector('[data-vote=open]')", 15, 'the vote');
    vbNoScroll($page);

    app(GameEngine::class)->closeVote($room, $round->round, force: true);
    vbWait($page, "document.querySelector('[data-vote=result]')", 10, 'the result');
    vbNoScroll($page);
    $page->assertNoJavaScriptErrors();
})->with([
    '1280x720' => [1280, 720],
    '1920x1080' => [1920, 1080],
]);

it('gives the vote ten more seconds: the host presses +10 s once and both timers jump', function () {
    $host = User::factory()->create(['email' => 'host@lamma.test', 'preferred_locale' => 'en']);
    $room = voteRoom(players: 0, questions: 10, every: 5, host: $host, settings: ['hostScreenLocale' => HostScreenLocale::Both]);
    $code = $room->code;

    $hostScreen = visit('/login')->withLocale('en')->resize(1280, 720);
    $hostScreen->type('[name=email]', $host->email)->type('[name=password]', 'password')->click('[data-test=login-button]');
    vbWait($hostScreen, "location.pathname === '/me/games'", 15, 'the host login');

    $phone = visit("/join?code={$code}")->withLocale('en')->resize(390, 844);
    $phone->type('[data-test=nickname-input]', 'Sara')->click('[data-test=join-button]');
    vbWait($phone, "location.pathname === '/play/{$code}'", 15, 'the phone lobby');
    $room->players()->update(['left_at' => null, 'is_ready' => true, 'locale' => 'en']);
    RoomPlayer::factory()->for($room)->create(['nickname' => 'Ali', 'locale' => 'en', 'is_ready' => true, 'left_at' => null]);
    playToVote($room);

    $hostScreen->navigate("/host/{$code}");
    vbWait($hostScreen, "document.querySelector('[data-vote=open]')", 15, 'the vote on the host');
    $phone->navigate("/play/{$code}");
    vbWait($phone, "document.querySelector('[data-vote=vote]')", 15, 'the vote on the phone');
    vbNoScroll($hostScreen);

    // Past 40 s (the length this file sets) is only possible once the deadline moved. The phone follows by its poll; Reverb is not running.
    $seconds = "Number(document.querySelector('[data-test=timer] [x-text=secs]').textContent)";
    $hostScreen->assertVisible('[data-test=extend-vote]')->click('[data-test=extend-vote]');
    vbWait($hostScreen, "!document.querySelector('[data-test=extend-vote]')", 10, 'the button to go');
    vbWait($hostScreen, "{$seconds} > 40", 5, 'the host timer to jump');
    vbWait($phone, "{$seconds} > 40", 10, 'the phone timer to jump');
    vbNoScroll($hostScreen);

    // Still counting down, and only one clock on each screen.
    $before = $hostScreen->script("() => {$seconds}");
    $hostScreen->wait(2);
    expect($hostScreen->script("() => {$seconds}"))->toBeLessThan($before);
    $hostScreen->assertScript("document.querySelectorAll('[data-test=timer]').length === 1");
    $phone->assertScript("document.querySelectorAll('[data-test=timer]').length === 1");

    $hostScreen->assertNoJavaScriptErrors();
    $phone->assertNoJavaScriptErrors();
});
