<?php

use App\Game\GameEngine;
use App\Models\PlayerAnswer;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Database\Seeders\ContentSeeder;
use Illuminate\Support\Facades\Queue;

/*
 * The speed bonus and the x2 chip in a real browser: a phone answers one question and sees the real +N with its breakdown, and the bigger numbers still fit on a 360 px
 * phone and on the host screens. Reverb is not running: the screens follow by polling and by their own Livewire calls. The rules are in tests/Feature/Game/ScoringTest.php.
 * LAMMA_SHOTS=1 saves screenshots (tests/Browser/Screenshots).
 */

beforeEach(fn () => Queue::fake());

function sbShot($page, string $name): void
{
    if (getenv('LAMMA_SHOTS')) {
        $page->screenshot(fullPage: false, filename: $name);
    }
}

function sbWait($page, string $expression, int $seconds = 20, string $what = ''): void
{
    for ($i = 0; $i < $seconds * 2; $i++) {
        if ($page->script("() => Boolean({$expression})") === true) {
            return;
        }
        $page->wait(0.5);
    }

    throw new RuntimeException("Timed out after {$seconds}s waiting for: ".($what ?: $expression));
}

function sbNoScroll($page): void
{
    $page->assertScript('document.documentElement.scrollHeight <= window.innerHeight + 1')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth + 1');
}

/** A guest's phone joins $room through the page (so it has its own cookie) and is connected and ready. */
function sbPhone(Room $room, string $locale = 'en', int $width = 390, int $height = 844)
{
    $phone = visit("/join?code={$room->code}")->withLocale($locale)->resize($width, $height);
    $phone->type('[data-test=nickname-input]', 'Maximiliano')->click('[data-test=join-button]');
    sbWait($phone, "location.pathname === '/play/{$room->code}'", 15, 'the phone lobby');
    $room->players()->update(['left_at' => null, 'is_ready' => true, 'locale' => $locale]);

    return $phone;
}

it('shows the real +N with its breakdown on a phone after one question', function (string $locale, string $word) {
    $room = gameRoom(players: 0, questions: 2, settings: ['secondsPerQuestion' => 30]);
    $phone = sbPhone($room, $locale);
    $question = startGame($room);
    // Ten seconds into the question, so the bonus is visibly not the full one.
    $question->update(['started_at' => now()->subSeconds(10), 'ends_at' => now()->addSeconds(20)]);

    $phone->navigate("/play/{$room->code}");
    sbWait($phone, "document.querySelector('[data-test=answers]')", 15, 'the question');
    $phone->assertMissing('[data-test=double-chip]');

    $phone->click('[data-option="'.optionOf($question).'"]');
    sbWait($phone, "document.querySelector('[data-test=reveal-correct]')", 15, 'the reveal');

    $answer = PlayerAnswer::query()->sole();
    expect($answer->speed_bonus)->toBeLessThan(100)->toBeGreaterThan(0)->and($answer->points)->toBe(100 + $answer->speed_bonus);

    sbWait($phone, "document.querySelector('[data-test=points-gained]').textContent.trim() === '+{$answer->points}'", 5, 'the +N to count up');
    $phone->assertSeeIn('[data-test=points-breakdown]', "100 + {$answer->speed_bonus} {$word}")
        ->assertMissing('[data-test=double-chip]')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth + 1')
        ->assertNoJavaScriptErrors();
    sbShot($phone, "scoring-phone-reveal-{$locale}");
    $phone->wait(1)->assertNoAccessibilityIssues();
})->with([
    'English' => ['en', 'speed'],
    'Arabic' => ['ar', 'للسرعة'],
]);

it('fits 4-digit scores and the x2 chip on a 360 px phone: question, reveal and results', function () {
    $room = gameRoom(players: 0, questions: 2, settings: ['secondsPerQuestion' => 30]);
    $phone = sbPhone($room, width: 360, height: 640);
    $me = $room->players()->first();
    $rival = RoomPlayer::factory()->for($room)->create(['nickname' => 'مريم الزهراء', 'locale' => 'ar', 'is_ready' => true, 'left_at' => null]);
    startGame($room);
    $engine = app(GameEngine::class);
    $engine->reveal($room, 1, force: true);
    $engine->advance($room, 1, force: true);
    $me->update(['score' => 3950]);
    $rival->update(['score' => 4200]);
    $question = $room->fresh()->currentQuestion();

    $phone->navigate("/play/{$room->code}");
    sbWait($phone, "document.querySelector('[data-test=answers]')", 15, 'the last question');
    $phone->assertVisible('[data-test=double-chip]')->assertSeeIn('[data-test=score-chip]', '3950')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth + 1')
        ->assertScript("document.querySelector('[data-test=score-chip]').getBoundingClientRect().right <= window.innerWidth");
    sbShot($phone, 'scoring-360-question');

    $phone->click('[data-option="'.optionOf($question).'"]');
    $engine->submitAnswer($rival, optionOf($question, false));
    sbWait($phone, "document.querySelector('[data-test=reveal-correct]')", 15, 'the reveal');
    $phone->assertVisible('[data-test=double-chip]')->assertSeeIn('[data-test=points-breakdown]', 'final question')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth + 1');
    sbShot($phone, 'scoring-360-reveal');

    $engine->advance($room, 2, force: true);
    sbWait($phone, "document.querySelector('[data-test=player-results]')", 15, 'the results');
    $phone->assertSeeIn('[data-test=leaderboard]', '4200')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth + 1')
        ->assertScript("[...document.querySelectorAll('[data-test=leaderboard] li')].every((row) => row.scrollWidth <= row.clientWidth + 1)")
        ->assertNoJavaScriptErrors();
    sbShot($phone, 'scoring-360-results');
});

it('does not scroll with the x2 chip and 4-digit scores on the host, from 1280x720 to 1920x1080', function (int $width, int $height) {
    $host = User::factory()->create(['preferred_locale' => 'en']);
    $room = gameRoom(players: 8, questions: 2, host: $host, settings: ['secondsPerQuestion' => 30]);
    $this->actingAs($host);
    startGame($room);
    $engine = app(GameEngine::class);
    $engine->reveal($room, 1, force: true);
    $engine->advance($room, 1, force: true);
    foreach ($room->players()->orderBy('id')->get() as $i => $player) {
        $player->update(['score' => 4200 - $i * 310, 'nickname' => $i % 2 ? "مريم الزهراء {$i}" : "Maximiliano {$i}"]);
    }

    $page = visit("/host/{$room->code}")->resize($width, $height);
    sbWait($page, "document.querySelector('[data-test=answers]')", 15, 'the last question');
    $page->assertVisible('[data-test=double-chip]');
    sbNoScroll($page);
    sbShot($page, "scoring-host-question-{$width}");

    $question = $room->fresh()->currentQuestion();
    foreach ($room->players()->orderBy('id')->get() as $i => $player) {
        answer($player, $question, $i % 3 !== 0);
    }

    $page->navigate("/host/{$room->code}");
    sbWait($page, "document.querySelector('[data-test=correct-count]')", 15, 'the reveal');
    $page->assertVisible('[data-test=double-chip]');
    sbNoScroll($page);
    sbShot($page, "scoring-host-reveal-{$width}");

    $engine->advance($room, 2, force: true);
    $page->navigate("/host/{$room->code}");
    sbWait($page, "document.querySelector('[data-test=host-results]')", 15, 'the podium');
    $page->wait(4);
    sbNoScroll($page);
    sbShot($page, "scoring-host-podium-{$width}");
    $page->assertNoJavaScriptErrors();
})->with(['1280x720' => [1280, 720], '1920x1080' => [1920, 1080]]);

it('does not scroll on create room at 1280x720 with the new setting, in Arabic too', function (string $locale) {
    (new ContentSeeder)->run();
    $this->actingAs(User::factory()->create(['preferred_locale' => $locale]));

    $page = visit('/rooms/create')->withLocale($locale)->resize(1280, 720);
    sbWait($page, "document.querySelector('[data-test=double-final-setting]')", 15, 'create room');
    sbNoScroll($page);

    $page->click('[data-test=mode] button:nth-child(2)');
    sbWait($page, "document.querySelector('[data-test=vote-setup]')", 10, 'the Vote setup');
    sbNoScroll($page);
    $page->assertNoJavaScriptErrors();
})->with(['English' => 'en', 'Arabic' => 'ar']);
