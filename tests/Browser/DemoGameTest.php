<?php

use App\Enums\RoomStatus;
use App\Game\GameStats;
use App\Jobs\BotAnswer;
use App\Models\PlayerAnswer;
use App\Models\Room;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Queue;
use Tests\Browser\Support\ForgetGuards;

/*
 * A whole demo game in two real browsers (Chromium): the big screen (no account: it starts from /demo) and a phone (an Arabic-speaking guest). Sara and Karim, the
 * bots, answer through BotAnswer jobs. The queue is faked except for those: the test queue is `sync`, which runs a delayed job at once, so the bots answer the
 * moment each question opens (the 25-85% timing is in tests/Feature/Game/DemoBotsTest.php). Everything else is a real click: start the demo, join with the
 * code, Ready, Start, answer, and watch both screens up to the end screens.
 *
 * Reverb is not running (as in FullGameTest): the host screen's tick moves the game, the phone polls. Marking the phone as connected is what a presence
 * connection would do.
 *
 * Run:  timeout 400 vendor/bin/pest tests/Browser/DemoGameTest.php   (LAMMA_SHOTS=1 saves screenshots in tests/Browser/Screenshots)
 */

beforeEach(function () {
    app(Kernel::class)->prependMiddleware(ForgetGuards::class);
    Queue::fake()->except(BotAnswer::class);
});

function demoWait($page, string $expression, int $seconds = 25, string $what = ''): void
{
    for ($i = 0; $i < $seconds * 2; $i++) {
        if ($page->script("() => Boolean({$expression})") === true) {
            return;
        }
        $page->wait(0.5);
    }

    throw new RuntimeException("Timed out after {$seconds}s waiting for: ".($what ?: $expression));
}

function demoShot($page, string $name): void
{
    if (getenv('LAMMA_SHOTS')) {
        $page->screenshot(fullPage: false, filename: $name);
    }
}

it('plays a whole demo game: no account, two bots, and an end screen that offers an account instead of Play again', function () {
    demoContent();

    // ---- the big screen: /demo, then Start a demo game ----
    $host = visit('/demo')->withLocale('en')->resize(1280, 720);
    $host->assertSee('Feel the game in two minutes.')->assertSee('Start a demo game');
    demoShot($host, 'demo-1-page');
    $host->click('[data-test=start-demo]');
    demoWait($host, "document.querySelector('[data-test=room-code]')", 20, 'the demo lobby');

    $room = Room::sole();
    $code = $room->code;
    expect($room->is_demo)->toBeTrue()->and($room->host_id)->toBeNull();
    $host->assertSeeIn('[data-test=player-list]', 'Sara')->assertSeeIn('[data-test=player-list]', 'Karim')->assertSee('Demo')
        ->assertScript("document.querySelectorAll('[data-test=bot-tag]').length === 2")->assertScript("document.querySelector('[data-test=start-button]').disabled === true");
    demoShot($host, 'demo-2-lobby');

    // Nobody real has joined: the start stays locked even though the bots are ready.
    expect($room->players()->where('is_bot', true)->connected()->where('is_ready', true)->count())->toBe(2);

    // ---- the phone: join with the code ----
    $phone = visit("/join?code={$code}")->withLocale('ar')->resize(390, 844);
    $phone->type('[data-test=nickname-input]', 'ليلى')->click('[data-test=join-button]');
    demoWait($phone, "location.pathname === '/play/{$code}'", 15, 'the phone to join');
    $room->players()->where('is_bot', false)->update(['left_at' => null]);

    $phone->click('[data-test=ready-button]');
    demoWait($phone, "document.querySelector('[data-test=ready-button]').getAttribute('aria-pressed') === 'true'", 10, 'Ready to register');
    demoWait($host, "document.querySelector('[data-test=ready-count]').textContent.includes('3 of 3')", 20, 'the host to see all three ready');
    demoWait($host, "document.querySelector('[data-test=start-button]') && ! document.querySelector('[data-test=start-button]').disabled", 20, 'Start game to unlock');
    $host->assertNoJavaScriptErrors();

    // ---- 4 questions: the phone answers, the bots already have ----
    $host->click('[data-test=start-button]');
    foreach ([1, 2, 3, 4] as $question) {
        demoWait($phone, "document.querySelector('[data-test=player-game]')?.dataset.phase === 'question'", 30, "question {$question} on the phone");
        if ($question === 1) {
            $phone->assertScript("document.documentElement.dir === 'rtl'");
            demoShot($phone, 'demo-3-phone-question');
            demoShot($host, 'demo-3-host-question');
        }

        // Sara and Karim answered when the question opened (sync queue), so the phone's tap is the last one in: the reveal comes at once.
        $phone->script("() => document.querySelectorAll('[data-test=answers] button')[0].click()");
        demoWait($phone, "['correct','wrong'].includes(document.querySelector('[data-test=player-game]')?.dataset.phase)", 20, "the reveal of question {$question}");
        demoWait($host, "document.querySelector('[data-test=correct-count]')", 20, "the host's reveal of question {$question}");
        expect(PlayerAnswer::whereHas('roomPlayer', fn ($players) => $players->where('is_bot', true))->count())->toBe($question * 2);
    }

    // ---- the end ----
    demoWait($host, "document.querySelector('[data-test=host-results]')", 40, 'the podium');
    demoWait($phone, "document.querySelector('[data-test=player-results]')", 40, 'the phone results');
    expect($room->fresh()->status)->toBe(RoomStatus::Finished)->and($room->fresh()->isCompleted())->toBeTrue();
    $room->players()->where('is_bot', true)->get()->each(fn ($bot) => expect($bot->answers()->count())->toBe(4));

    // Host: an account or a friend's game; no Play again, no New game.
    $host->assertScript("document.querySelector('[data-test=host-your-own]').getAttribute('href').endsWith('/register')")
        ->assertScript("document.querySelector('[data-test=join-a-friend]').getAttribute('href').endsWith('/join')")
        ->assertScript("document.querySelector('[data-test=play-again]') === null && document.querySelector('[data-test=new-game]') === null")
        ->assertScript("document.querySelector('[data-test=share-result]') !== null")
        ->assertScript('document.documentElement.scrollHeight <= window.innerHeight + 1');
    // Phone: the same two, instead of "Save your score"; all three players on the board.
    $phone->assertScript("document.querySelector('[data-test=save-card] [data-state=demo]') !== null && document.querySelector('[data-test=save-login]') === null")
        ->assertScript("document.querySelectorAll('[data-test=leaderboard] li').length === 3")
        ->assertSeeIn('[data-test=leaderboard]', 'Sara')->assertSeeIn('[data-test=leaderboard]', 'Karim')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth');
    demoShot($host, 'demo-4-host-end');
    demoShot($phone, 'demo-4-phone-end');

    // Only that browser can open this big screen: a stranger with the same address gets a 403.
    $stranger = visit("/host/{$code}")->withLocale('en');
    $stranger->assertSee('403');

    foreach ([$host, $phone] as $screen) {
        $screen->assertNoJavaScriptErrors();
    }

    // The demo is in no statistic and no one's history.
    expect(app(GameStats::class)->gamesPerDay(30))->each->toBe(0);
});

it('says on a small screen to open the demo on a bigger one, and still starts one', function () {
    demoContent();

    $page = visit('/demo')->withLocale('en')->resize(390, 844);

    $page->assertSee("You're on a small screen")->assertScript("document.querySelector('[data-test=start-demo]').getBoundingClientRect().width > 100")
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')->assertNoJavaScriptErrors();

    $wide = visit('/demo')->withLocale('en')->resize(1440, 900);
    $wide->assertScript("getComputedStyle(document.querySelector('[data-test=small-screen-note]')).display === 'none'");
});

it('plays a whole demo with the queue worker stopped: the big screen\'s own tick answers for the bots', function () {
    Queue::fake();   // not even BotAnswer runs: the only thing that can answer for Sara and Karim is GameEngine::tick(), called by the host screen's poll (Phase 14.1)
    demoContent();

    $host = visit('/demo')->withLocale('en')->resize(1280, 720);
    $host->click('[data-test=start-demo]');
    demoWait($host, "document.querySelector('[data-test=room-code]')", 20, 'the demo lobby');
    $room = Room::sole();
    $code = $room->code;

    $phone = visit("/join?code={$code}")->withLocale('en')->resize(390, 844);
    $phone->type('[data-test=nickname-input]', 'Laila')->click('[data-test=join-button]');
    demoWait($phone, "location.pathname === '/play/{$code}'", 15, 'the phone to join');
    $room->players()->where('is_bot', false)->update(['left_at' => null]);
    $phone->click('[data-test=ready-button]');
    demoWait($host, "document.querySelector('[data-test=ready-count]').textContent.includes('3 of 3')", 20, 'the host to see all three ready');
    demoWait($host, "document.querySelector('[data-test=start-button]') && ! document.querySelector('[data-test=start-button]').disabled", 20, 'Start game to unlock');
    $host->click('[data-test=start-button]');

    foreach ([1, 2, 3, 4] as $question) {
        demoWait($phone, "document.querySelector('[data-test=player-game]')?.dataset.phase === 'question'", 40, "question {$question} on the phone");
        // Nobody has answered for the bots: they are not in yet, however long the phone waits... until the host screen's tick passes their planned moments.
        expect(PlayerAnswer::whereHas('roomPlayer', fn ($players) => $players->where('is_bot', true))->count())->toBe(($question - 1) * 2);
        $phone->script("() => document.querySelectorAll('[data-test=answers] button')[0].click()");
        demoWait($phone, "['correct','wrong'].includes(document.querySelector('[data-test=player-game]')?.dataset.phase)", 40, "the reveal of question {$question}");
        demoWait($host, "document.querySelector('[data-test=correct-count]')", 20, "the host's reveal of question {$question}");
        expect(PlayerAnswer::whereHas('roomPlayer', fn ($players) => $players->where('is_bot', true))->count())->toBe($question * 2);
    }

    demoWait($host, "document.querySelector('[data-test=host-results]')", 60, 'the podium');
    demoWait($phone, "document.querySelector('[data-test=player-results]')", 40, 'the phone results');
    expect($room->fresh()->isCompleted())->toBeTrue();
    $room->players()->where('is_bot', true)->get()->each(fn ($bot) => expect($bot->answers()->count())->toBe(4));
    expect(Queue::pushed(BotAnswer::class))->toHaveCount(8);   // queued (a worker would have used them), never run
    foreach ([$host, $phone] as $screen) {
        $screen->assertNoJavaScriptErrors();
    }
});
