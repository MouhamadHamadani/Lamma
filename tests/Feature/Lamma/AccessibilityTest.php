<?php

use App\Enums\HostScreenLocale;
use App\Events\GameFinished;
use App\Events\GameStarted;
use App\Events\PlayerAnswered;
use App\Events\QuestionRevealed;
use App\Events\QuestionStarted;
use App\Events\ScoreboardUpdated;
use App\Game\GameEngine;
use App\Livewire\Host\HostGame;
use App\Livewire\Host\HostLobby;
use App\Livewire\Host\HostResults;
use App\Livewire\Player\PlayerGame;
use App\Livewire\Player\PlayerResults;
use App\Models\Room;
use Filament\Support\Colors\Color;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/** The colour tokens of resources/css/lamma-theme.css (the design handoff), by name. */
function tokens(): array
{
    preg_match_all('/--color-([a-z0-9-]+):\s*(#[0-9A-Fa-f]{6})/', file_get_contents(resource_path('css/lamma-theme.css')), $matches, PREG_SET_ORDER);

    return collect($matches)->mapWithKeys(fn (array $m) => [$m[1] => $m[2]])->all();
}

/** WCAG contrast of $foreground over $background, both token names; an optional alpha blends the foreground over the background first. */
function contrastOf(string $foreground, string $background, float $alpha = 1.0): float
{
    $colors = tokens();
    $fg = $colors[$foreground];
    $bg = $colors[$background];

    if ($alpha < 1.0) {
        $mix = fn (int $f, int $b) => (int) round($f * $alpha + $b * (1 - $alpha));
        [$fr, $fgr, $fb] = sscanf($fg, '#%02x%02x%02x');
        [$br, $bgr, $bb] = sscanf($bg, '#%02x%02x%02x');
        $fg = sprintf('#%02X%02X%02X', $mix($fr, $br), $mix($fgr, $bgr), $mix($fb, $bb));
    }

    return Color::calculateContrastRatio($fg, $bg);
}

describe('contrast tokens (HANDOFF section 8: every text colour combination passes WCAG AA)', function () {
    it('has the tokens the checks below use', function () {
        expect(tokens())->toHaveKeys(['navy', 'cream', 'coral', 'coral-700', 'sun', 'teal', 'ink-muted', 'ink-subtle', 'ink-on-dark', 'navy-600', 'navy-700', 'tint-coral', 'tint-sun', 'tint-teal', 'tint-navy']);
    });

    it('passes AA for navy text on every light and brand surface it sits on', function ($background) {
        expect(contrastOf('navy', $background))->toBeGreaterThanOrEqual(Color::WCAG_AA_TEXT);
    })->with(['cream', 'coral', 'sun', 'teal', 'tint-coral', 'tint-sun', 'tint-teal', 'tint-navy']);

    it('passes AA for light text on the navy surfaces', function (string $foreground, string $background) {
        expect(contrastOf($foreground, $background))->toBeGreaterThanOrEqual(Color::WCAG_AA_TEXT);
    })->with([
        ['cream', 'navy'], ['cream', 'navy-700'], ['cream', 'navy-600'],
        ['ink-on-dark', 'navy'], ['ink-on-dark', 'navy-700'], ['ink-on-dark', 'navy-600'],
        ['sun', 'navy'], ['sun', 'navy-700'], ['teal', 'navy'], ['teal', 'navy-700'], ['coral', 'navy'],
    ]);

    it('passes AA for coral as text only in its dark shade, on cream and white', function (string $background) {
        expect(contrastOf('coral-700', $background))->toBeGreaterThanOrEqual(Color::WCAG_AA_TEXT);
        // the brand coral is for fills: as text on cream it would fail
        expect(contrastOf('coral', 'cream'))->toBeLessThan(Color::WCAG_AA_TEXT);
    })->with(['cream']);

    it('passes AA for the grey body and caption text on cream and white', function (string $foreground) {
        expect(contrastOf($foreground, 'cream'))->toBeGreaterThanOrEqual(Color::WCAG_AA_TEXT);
    })->with(['ink-muted', 'ink-subtle']);

    it('passes AA for the "points gained" text, which is the light ink at 70% on navy', function () {
        expect(contrastOf('ink-on-dark', 'navy-700', 0.7))->toBeGreaterThanOrEqual(Color::WCAG_AA_TEXT);
    });

    it('never puts white or cream text on coral, sun or teal in the views', function () {
        $offenders = collect(File::allFiles(resource_path('views')))
            ->filter(fn ($file) => str_ends_with($file->getFilename(), '.blade.php') && ! str_contains($file->getPathname(), 'settings'))
            ->flatMap(function ($file) {
                preg_match_all('/class="([^"]*)"/', file_get_contents($file->getPathname()), $classes);

                return collect($classes[1])
                    ->filter(fn (string $class) => preg_match('/(^|\s)text-(white|cream)(\s|$)/', $class) && preg_match('/(^|\s)bg-(coral|sun|teal)(\s|$)/', $class))
                    ->map(fn (string $class) => $file->getFilename().': '.$class);
            })->values()->all();

        expect($offenders)->toBe([]);
    });
});

describe('focus', function () {
    it('shows a 3px sun outline with a 2px offset on keyboard focus only', function () {
        $css = file_get_contents(resource_path('css/app.css'));

        expect($css)->toContain(':focus-visible')->toContain('outline: 3px solid var(--color-sun)')->toContain('outline-offset: 2px');
    });

    it('lists the actions of the results screens in reading order', function () {
        Event::fake([GameStarted::class, GameFinished::class]);
        Queue::fake();
        $room = finishedGame(['Ali' => 800, 'Sara' => 700]);
        $room->players()->where('nickname', 'Sara')->update(['guest_token' => str_repeat('f', 64), 'locale' => 'en']);

        $phone = Livewire::withCookie('lamma_guest', str_repeat('f', 64))->test(PlayerResults::class, ['room' => $room])->html();
        $host = Livewire::actingAs($room->host)->test(HostResults::class, ['room' => $room])->html();

        expect(strpos($host, 'data-test="play-again"'))->toBeLessThan(strpos($host, 'data-test="new-game"'))->and(strpos($host, 'data-test="new-game"'))->toBeLessThan(strpos($host, 'Back to home'))
            ->and(strpos($phone, 'data-test="leaderboard"'))->toBeLessThan(strpos($phone, 'data-test="save-login"'))->and(strpos($phone, 'data-test="save-register"'))->toBeLessThan(strpos($phone, 'data-test="leave-button"'));
    });
});

describe('motion (HANDOFF section 7: reduced motion replaces movement with fades)', function () {
    it('only animates behind motion-safe, with a motion-reduce fallback for the entrances', function () {
        $bare = collect(File::allFiles(resource_path('views')))
            ->filter(fn ($file) => str_ends_with($file->getFilename(), '.blade.php') && ! str_contains($file->getPathname(), 'settings'))
            ->flatMap(function ($file) {
                preg_match_all('/(?<![:\w-])animate-[a-z-]+/', file_get_contents($file->getPathname()), $found);

                return collect($found[0])->map(fn (string $class) => $file->getFilename().': '.$class);
            })->values()->all();

        expect($bare)->toBe([]);
    });

    it('stops the sticker press from sliding under reduced motion', function () {
        expect(file_get_contents(resource_path('css/app.css')))->toContain('prefers-reduced-motion: reduce')->toContain('.sticker-press { transition: none; }');
    });
});

describe('live regions (HANDOFF section 8)', function () {
    beforeEach(function () {
        Event::fake([GameStarted::class, QuestionStarted::class, PlayerAnswered::class, QuestionRevealed::class, ScoreboardUpdated::class, GameFinished::class]);
        Queue::fake();
    });

    it('announces joins and leaves on the host lobby', function () {
        $room = Room::factory()->create();
        $page = Livewire::actingAs($room->host)->test(HostLobby::class, ['room' => $room]);
        $page->assertSeeHtml('role="status" aria-live="polite" aria-atomic="true" data-test="announcer"');

        $page->dispatch("echo-presence:room.{$room->code},PlayerJoined", ['player' => ['nickname' => 'Sara']]);
        expect($page->html())->toMatch('/data-test="announcer">\s*Sara joined\s*</');

        $page->dispatch("echo-presence:room.{$room->code},PlayerLeft", ['player' => ['nickname' => 'Sara']]);
        expect($page->html())->toMatch('/data-test="announcer">\s*Sara left\s*</');
    });

    it('does not announce a join that carries no name', function () {
        $room = Room::factory()->create();

        $page = Livewire::actingAs($room->host)->test(HostLobby::class, ['room' => $room])->dispatch("echo-presence:room.{$room->code},PlayerJoined");

        expect($page->html())->toMatch('/data-test="announcer">\s*</');
    });

    it('announces the question number on the host screen, then the right answer', function () {
        $room = gameRoom(players: 1, settings: ['hostScreenLocale' => HostScreenLocale::En]);
        startGame($room);
        $page = Livewire::actingAs($room->host)->test(HostGame::class, ['room' => $room]);

        expect($page->html())->toMatch('/data-test="announcer">\s*Question 1 of 3\s*</');

        $page->call('skipTimer', 1);
        expect($page->html())->toMatch('/data-test="announcer">\s*The correct answer is /');
    });

    it('announces the phone\'s states in the words of the handoff', function () {
        $room = gameRoom(players: 2);
        $room->players()->orderBy('id')->first()->update(['guest_token' => str_repeat('l', 64), 'locale' => 'en']);
        $question = startGame($room);
        $me = $room->players()->orderBy('id')->first();
        app(GameEngine::class)->submitAnswer($me, optionOf($question));
        $page = Livewire::withCookie('lamma_guest', str_repeat('l', 64))->test(PlayerGame::class, ['room' => $room]);
        expect($page->html())->toMatch('/data-test="announcer">\s*Answer locked in\s*</');

        app(GameEngine::class)->reveal($room, 1, force: true);
        $page = Livewire::withCookie('lamma_guest', str_repeat('l', 64))->test(PlayerGame::class, ['room' => $room]);
        expect($page->html())->toMatch('/data-test="announcer">\s*Correct, plus 100 points\s*</');
    });

    it('names every answer button "Answer B: Mars" and hides the shapes', function () {
        $html = Blade::render('<x-lamma.answer size="phone" :index="1" text="Mars" />');

        expect($html)->toContain('aria-label="Answer B: Mars"')->toContain('aria-hidden="true"');
    });
});

describe('right-to-left (HANDOFF section 6)', function () {
    /** The Lamma screens and components (not the starter kit's settings pages). */
    function lammaViews(): array
    {
        return collect(['livewire', 'components/lamma'])
            ->flatMap(fn (string $dir) => File::allFiles(resource_path("views/{$dir}")))
            ->filter(fn ($file) => str_ends_with($file->getFilename(), '.blade.php'))
            ->all();
    }

    it('uses logical utilities only: no ml-, mr-, pl-, pr-, left-, right-, text-left or text-right', function () {
        $offenders = collect(lammaViews())->flatMap(function ($file) {
            preg_match_all('/class="([^"]*)"/', file_get_contents($file->getPathname()), $classes);

            return collect($classes[1])->flatMap(fn (string $class) => preg_grep('/^(-?(ml|mr|pl|pr|left|right)-|text-(left|right)$)/', preg_split('/\s+/', $class)))
                ->map(fn (string $class) => $file->getFilename().': '.$class);
        })->values()->all();

        expect($offenders)->toBe([]);
    });

    it('isolates numbers and names, never a whole translated phrase (that would scramble "4 من 5")', function () {
        $offenders = collect(lammaViews())->flatMap(function ($file) {
            preg_match_all("/dir=\"ltr\"[^>]*>\s*\{[{!]+\s*__\('([^']+)'/", file_get_contents($file->getPathname()), $found);

            // a label followed by its value ("Q 1 / 5", "Room K7MP") is one left-to-right chip, like the room code itself
            return collect($found[1])->reject(fn (string $key) => in_array($key, ['Q :n / :total', 'Room'], true))->map(fn (string $key) => $file->getFilename().': '.$key);
        })->values()->all();

        expect($offenders)->toBe([]);
    });
});
