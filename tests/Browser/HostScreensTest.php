<?php

use App\Enums\HostScreenLocale;
use App\Game\GameEngine;
use App\Models\Room;
use App\Models\User;
use Database\Seeders\ContentSeeder;
use Illuminate\Support\Facades\Queue;

/*
 * The host screens are for a laptop or a TV and must never scroll during a game, from 1280x720 up to 1920x1080 (HANDOFF section 5).
 * Each state below is built in the database (the way the game tests do) and then opened in a real browser as the host.
 */

dataset('host screens', [
    '1280x720' => [1280, 720],
    '1920x1080' => [1920, 1080],
]);

/** The page neither scrolls vertically nor sideways. */
function assertDoesNotScroll($page): void
{
    $page->assertScript('document.documentElement.scrollHeight <= window.innerHeight + 1')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth + 1')
        ->assertScript('document.body.scrollHeight <= window.innerHeight + 1');
}

/**
 * The axe audit (critical and serious problems), as assertNoAccessibilityIssues() runs it, but able to leave out what is deliberately
 * dimmed: on the reveal the wrong answers fade to 35% (HANDOFF section 7), which axe reads as low contrast.
 */
function assertAccessible($page, array $exclude = []): void
{
    $skip = json_encode(array_map(fn (string $selector) => [$selector], $exclude));
    $violations = $page->script("async () => (await window.axe.run({ exclude: {$skip} })).violations
        .filter((violation) => ['critical', 'serious'].includes(violation.impact))
        .map((violation) => violation.id + ': ' + violation.nodes.map((node) => node.target.join(' ')).join(' | '))");

    expect($violations)->toBe([]);
}

/** A host, and a room of $players connected, ready players in a game of $questions questions. */
function hostedRoom(int $players = 8, int $questions = 5): array
{
    $host = User::factory()->create(['preferred_locale' => 'en']);
    $category = categoryWithQuestions(20);
    $room = gameRoom(players: $players, questions: $questions, settings: ['secondsPerQuestion' => 30], host: $host, category: $category);

    // Names of different shapes and lengths, in both languages: the layouts must cope with real ones.
    $names = ['Sara', 'علي', 'Maximiliano', 'مريم الزهراء', 'Jo', 'عبد الرحمن', 'Alexandria', 'ليلى'];
    foreach ($room->players()->orderBy('id')->get() as $i => $player) {
        $player->update(['nickname' => $names[$i % count($names)].($i >= count($names) ? $i : ''), 'locale' => $i % 2 ? 'ar' : 'en']);
    }

    return [$host, $room->fresh()];
}

beforeEach(fn () => Queue::fake());

it('does not scroll on the create-room screen', function (int $width, int $height) {
    (new ContentSeeder)->run();
    $this->actingAs(User::factory()->create(['preferred_locale' => 'en']));

    $page = visit('/rooms/create')->resize($width, $height);

    $page->assertSee('Set up your game');
    assertDoesNotScroll($page);
    $page->wait(1)->assertNoAccessibilityIssues();
})->with('host screens');

it('does not scroll in the lobby, with a full room', function (int $width, int $height) {
    [$host, $room] = hostedRoom(players: 10);
    $this->actingAs($host);

    $page = visit("/host/{$room->code}")->resize($width, $height);

    $page->assertSeeIn('[data-test=ready-count]', 'of 10 ready');
    assertDoesNotScroll($page);
    $page->wait(1)->assertNoAccessibilityIssues();
})->with('host screens');

it('does not scroll on a question, with the timer running and players answering', function (int $width, int $height) {
    [$host, $room] = hostedRoom();
    $this->actingAs($host);
    $question = startGame($room);
    foreach ($room->players()->take(3)->get() as $player) {
        answer($player, $question, true);
    }

    $page = visit("/host/{$room->code}")->resize($width, $height);

    $page->assertSeeIn('[data-test=host-game]', '3 of 8 answered');
    assertDoesNotScroll($page);
    $page->wait(1)->assertNoAccessibilityIssues();
})->with('host screens');

it('does not scroll on the reveal, with the scoreboard and the pickers on the tiles', function (int $width, int $height) {
    [$host, $room] = hostedRoom();
    $this->actingAs($host);
    $question = startGame($room);
    foreach ($room->players as $i => $player) {
        answer($player, $question, $i % 3 !== 0);
    }
    app(GameEngine::class)->reveal($room, 1);

    $page = visit("/host/{$room->code}")->resize($width, $height);

    $page->assertScript("document.querySelector('[data-test=correct-count]') !== null");
    assertDoesNotScroll($page);
    assertAccessible($page->wait(1), exclude: ['.opacity-35']);
})->with('host screens');

it('does not scroll on the podium', function (int $width, int $height) {
    $host = User::factory()->create(['preferred_locale' => 'en']);
    $room = finishedGame(['Sara' => 500, 'علي' => 400, 'Maximiliano' => 400, 'مريم' => 300, 'Jo' => 100], host: $host);
    $this->actingAs($host);

    $page = visit("/host/{$room->code}")->resize($width, $height);

    $page->assertSeeIn('[data-test=host-results]', 'Play again');
    assertDoesNotScroll($page);
    $page->wait(4);                                  // the podium builds up for about three seconds
    $page->assertNoAccessibilityIssues();
})->with('host screens');

it('does not scroll on the podium when nobody scored, or only one player came', function (int $width, int $height) {
    $host = User::factory()->create(['preferred_locale' => 'en']);
    $room = finishedGame(['Solo' => 200], host: $host);
    $this->actingAs($host);

    $page = visit("/host/{$room->code}")->resize($width, $height);

    $page->assertScript("document.querySelector('[data-test=headline]').textContent.includes('Solo')");
    assertDoesNotScroll($page);
})->with('host screens');

it('does not scroll in Arabic either', function (int $width, int $height) {
    $host = User::factory()->create(['preferred_locale' => 'ar']);
    $room = gameRoom(players: 6, questions: 5, host: $host, settings: ['hostScreenLocale' => HostScreenLocale::Ar, 'secondsPerQuestion' => 30]);
    startGame($room);
    $this->actingAs($host);

    $page = visit("/host/{$room->code}")->resize($width, $height);

    $page->assertScript("document.documentElement.dir === 'rtl'");
    assertDoesNotScroll($page);
})->with('host screens');
