<?php

use App\Enums\HostScreenLocale;
use App\Events\GameFinished;
use App\Events\GameStarted;
use App\Events\PlayerAnswered;
use App\Events\QuestionRevealed;
use App\Events\QuestionStarted;
use App\Events\ScoreboardUpdated;
use App\Livewire\Host\HostGame;
use App\Livewire\Host\HostLobby;
use App\Livewire\Host\HostResults;
use App\Livewire\Player\PlayerGame;
use App\Livewire\Player\PlayerLobby;
use App\Livewire\Player\PlayerResults;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

const SOUND_TOKEN = 's0nds0nds0nds0nds0nds0nds0nds0nds0nds0nds0nds0nds0nds0nds0nds0nd';

beforeEach(function () {
    Event::fake([GameStarted::class, QuestionStarted::class, PlayerAnswered::class, QuestionRevealed::class, ScoreboardUpdated::class, GameFinished::class]);
    Queue::fake();
});

/** The sound names the screens and scripts ask for: the quoted name (or the two of a ternary) right after each .play( . */
function soundNamesAskedFor(): array
{
    $files = collect(glob(resource_path('js/*.js')))
        ->merge(collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS)))->map(fn ($file) => $file->getPathname())->filter(fn ($path) => str_ends_with($path, '.blade.php')));

    return $files->flatMap(function (string $path) {
        // .play('name'), .play(cond ? 'a' : 'b'): the quoted name(s) right after the opening bracket
        preg_match_all("/\.play\((?:[^'\")]*\?\s*)?'([a-z]+(?:-[a-z]+)*)'(?:\s*:\s*'([a-z]+(?:-[a-z]+)*)')?/", file_get_contents($path), $found);

        return array_merge($found[1], array_filter($found[2]));
    })->unique()->sort()->values()->all();
}

describe('the sound files', function () {
    it('has one for each moment, as small valid WAV files', function () {
        foreach (['question-start', 'tick', 'reveal', 'podium'] as $name) {
            $path = public_path("sounds/{$name}.wav");

            expect($path)->toBeFile()->and(filesize($path))->toBeLessThan(60 * 1024);
            $header = file_get_contents($path, length: 12);
            expect(substr($header, 0, 4))->toBe('RIFF')->and(substr($header, 8, 4))->toBe('WAVE');
        }
    });

    it('is a few kilobytes in all', function () {
        $total = collect(glob(public_path('sounds/*')))->sum(fn (string $file) => filesize($file));

        expect($total)->toBeLessThan(150 * 1024);
    });
});

describe('the sound manager (resources/js/sound.js)', function () {
    beforeEach(fn () => $this->js = file_get_contents(resource_path('js/sound.js')));

    it('is one Alpine store, defined once and imported once by the bundle', function () {
        $definers = collect(glob(resource_path('js/*.js')))->filter(fn (string $file) => str_contains(file_get_contents($file), "Alpine.store('sound',"))->map(fn (string $file) => basename($file))->values()->all();

        expect($definers)->toBe(['sound.js'])
            ->and(substr_count(file_get_contents(resource_path('js/app.js')), "import './sound'"))->toBe(1)
            ->and(file_get_contents(resource_path('js/lamma.js')))->not->toContain("store('sound',");
    });

    it('offers play, mute, unmute, setVolume, unlock and isMuted', function () {
        foreach (['play(name', 'mute()', 'unmute()', 'setVolume(volume)', 'unlock()', 'isMuted()', 'buzz(kind'] as $method) {
            expect($this->js)->toContain($method);
        }
    });

    it('is silent until a gesture unlocks it, caps the voices at 6 and plays nothing while muted', function () {
        expect($this->js)->toContain("audio.ctx?.state !== 'running'")->toContain('MAX_VOICES = 6')->toContain('!this.enabled')
            ->toContain("['click', 'touchend', 'keydown']")->toContain('0.5 * this.volume');
    });

    it('has a synthesized sound for every name the screens ask for, each under 1.2 seconds', function () {
        $names = soundNamesAskedFor();
        $synth = substr($this->js, strpos($this->js, 'const SYNTH'), strpos($this->js, "document.addEventListener('alpine:init'") - strpos($this->js, 'const SYNTH'));

        expect($names)->not->toBeEmpty();
        foreach ($names as $name) {
            expect($synth)->toMatch('/(^|\n)    (\''.preg_quote($name, '/').'\'|'.preg_quote($name, '/').'): \(/');
        }
        preg_match_all('/return ([0-9.]+);/', $synth, $lengths);
        expect(max(array_map('floatval', $lengths[1])))->toBeLessThan(1.2);
    });

    it('plays a file from public/sounds when the page lists one and falls back to the synthesized sound', function () {
        expect($this->js)->toContain("FILE_OF = { 'timer-tick': 'tick', win: 'podium' }")->toContain('this.files.includes(file)')->toContain('// fall back to the synthesized sound');
    });

    it('remembers the choice, the volume and the haptics per device, and survives blocked storage', function () {
        expect($this->js)->toContain("'lamma:sound'")->toContain("'lamma:volume'")->toContain("'lamma:haptics'")
            ->and(substr_count($this->js, 'localStorage.getItem(') + substr_count($this->js, 'localStorage.setItem('))->toBe(2) // one read, one write: each in a try/catch
            ->and($this->js)->toContain('catch (e)');
    });

    it('is on for a host and off for a phone, and off for either when the OS asks for reduced motion', function () {
        expect($this->js)->toContain("role === 'host' && !reduced()")->toContain('prefers-reduced-motion: reduce');
    });

    it('vibrates only where the browser can, after the person has touched the page', function () {
        expect($this->js)->toContain("'vibrate' in navigator")->toContain('navigator.userActivation?.hasBeenActive === false')
            ->toContain('tap: 10, correct: 30, wrong: [20, 40, 20]');
    });

    it('plays a cue once per moment, because Livewire runs an x-init again whenever the markup around it changes', function () {
        $lamma = file_get_contents(resource_path('js/lamma.js'));
        $host = file_get_contents(resource_path('views/livewire/host/host-game.blade.php'));

        expect($this->js)->toContain('once(key, run)')->toContain('audio.fired.has(key)')
            ->and($host)->toContain("\$store.sound.once('q{{ \$position }}-start'")->toContain("\$store.sound.once('q{{ \$position }}-reveal'")
            ->and($lamma)->toContain('sound.once(`countdown-${n}`')->toContain('once(`tick-${endsAt}-${s}`')->toContain('once(`time-up-${endsAt}`');
    });

    it('keeps the timers and the countdown out of Livewire refreshes, so each runs once on its own clock', function () {
        foreach (['components/lamma/timer-ring', 'components/lamma/timer-bar', 'components/lamma/countdown'] as $view) {
            expect(file_get_contents(resource_path("views/{$view}.blade.php")))->toContain('wire:ignore x-data="lamma');
        }
        expect(file_get_contents(resource_path('views/livewire/host/host-game.blade.php')))->toContain('wire:ignore x-data="lammaTimer(');
    });

    it('adds under 15 KB gzipped of script and no raw colours', function () {
        $new = collect(['sound', 'fx', 'confetti'])->map(fn (string $name) => file_get_contents(resource_path("js/{$name}.js")))->implode("\n");

        expect(strlen(gzencode($new, 9)))->toBeLessThan(15 * 1024)
            ->and($new)->not->toMatch('/#[0-9a-fA-F]{6}\b/');
    });
});

describe('the toggles', function () {
    it('is a button with a name and an aria-pressed state: on for a host, off for a phone', function () {
        $host = Blade::render('<x-lamma.sound-toggle />');
        $phone = Blade::render('<x-lamma.sound-toggle :phone="true" />');

        expect($host)->toContain('<button')->toContain('aria-pressed="true"')->toContain('aria-label="Sounds"')->toContain('data-test="sound-toggle"')
            ->toContain('lammaSoundToggle(')->toContain('wire:ignore')->toContain('x-bind:aria-pressed')->toContain('host')->toContain('/sounds')
            ->and($phone)->toContain('aria-pressed="false"')->toContain('phone');
    });

    it('lists the files in public/sounds so the page never asks for one that is missing', function () {
        $html = Blade::render('<x-lamma.sound-toggle />');

        foreach (['podium', 'question-start', 'reveal', 'tick'] as $file) {
            expect($html)->toContain($file);
        }
    });

    it('speaks Arabic in Arabic', function () {
        app()->setLocale('ar');

        expect(Blade::render('<x-lamma.sound-toggle />'))->toContain('aria-label="الأصوات"')
            ->and(Blade::render('<x-lamma.haptics-toggle />'))->toContain('aria-label="الاهتزاز"');
    });

    it('has a variant for the navy results screen', function () {
        expect(Blade::render('<x-lamma.sound-toggle :on-dark="true" />'))->toContain('bg-navy-700')->not->toContain('bg-white')
            ->and(Blade::render('<x-lamma.haptics-toggle :on-dark="true" />'))->toContain('bg-navy-700');
    });

    it('has a vibration switch that is pressed to begin with, and hidden where the browser cannot vibrate', function () {
        $html = Blade::render('<x-lamma.haptics-toggle />');

        expect($html)->toContain('aria-pressed="true"')->toContain('aria-label="Vibration"')->toContain('data-test="haptics-toggle"')
            ->toContain('x-show="supported"')->toContain('lammaHapticsToggle')->toContain('wire:ignore');
    });

    it('is on every host screen: lobby, countdown, question, reveal and results', function () {
        $lobby = gameRoom(players: 1, settings: ['hostScreenLocale' => HostScreenLocale::En]);
        Livewire::actingAs($lobby->host)->test(HostLobby::class, ['room' => $lobby])->assertSeeHtml('data-test="sound-toggle"');

        $room = gameRoom(players: 1, settings: ['hostScreenLocale' => HostScreenLocale::En]);
        startGame($room);
        $page = Livewire::actingAs($room->host)->test(HostGame::class, ['room' => $room])->assertSeeHtml('data-test="sound-toggle"');
        $page->call('skipTimer', 1)->assertSeeHtml('data-test="sound-toggle"');
        Livewire::actingAs($room->host)->test(HostResults::class, ['room' => finishedGame(['Ali' => 100])])->assertSeeHtml('data-test="sound-toggle"');
    });

    it('is on the phone lobby, game and results, switched off to begin with, next to the vibration switch', function () {
        $room = gameRoom(players: 1);
        $room->players()->first()->update(['guest_token' => SOUND_TOKEN, 'locale' => 'en']);
        $phone = fn (string $component, $r = null) => Livewire::withCookie('lamma_guest', SOUND_TOKEN)->test($component, ['room' => $r ?? $room])->html();

        $lobby = $phone(PlayerLobby::class);
        startGame($room);
        $game = $phone(PlayerGame::class);

        foreach ([$lobby, $game] as $html) {
            expect($html)->toContain('data-test="sound-toggle"')->toContain('data-test="haptics-toggle"')->toContain('aria-pressed="false"');
        }
        $done = finishedGame(['Ali' => 100]);
        $done->players()->first()->update(['guest_token' => SOUND_TOKEN, 'locale' => 'en']);
        expect($phone(PlayerResults::class, $done))->toContain('data-test="sound-toggle"')->toContain('data-test="haptics-toggle"');
    });

    it('sits above the 3 · 2 · 1 overlay on both screens, so it can be switched from the first second', function () {
        $room = gameRoom(players: 1, settings: ['hostScreenLocale' => HostScreenLocale::En]);

        expect(file_get_contents(resource_path('views/livewire/host/host-game.blade.php')))->toContain('<x-lamma.sound-toggle class="relative z-50" />')
            ->and(file_get_contents(resource_path('views/livewire/player/player-game.blade.php')))->toContain('relative z-50 flex h-16');
    });
});

describe('when they play', function () {
    it('plays the start sound with each question and the reveal sound with each answer, through the sound store', function () {
        $room = gameRoom(players: 1);
        startGame($room);
        $page = Livewire::actingAs($room->host)->test(HostGame::class, ['room' => $room]);

        $page->assertSeeHtml("\$store.sound.play('question-start')");
        $page->call('skipTimer', 1)->assertSeeHtml("\$store.sound.play('reveal')");
    });

    it('ticks for the last five seconds of a question only, not of the pause before the next one', function () {
        $js = file_get_contents(resource_path('js/lamma.js'));

        expect($js)->toContain("play('timer-tick')")->toContain('ticks && s <= 5 && s > 0')->toContain("play('time-up')");
        expect(file_get_contents(resource_path('views/components/lamma/timer-ring.blade.php')))->toContain(', true)"');
        expect(file_get_contents(resource_path('views/livewire/host/host-game.blade.php')))->toContain("''".')" data-test="next-countdown"');
    });
});
