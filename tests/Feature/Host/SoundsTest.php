<?php

use App\Enums\HostScreenLocale;
use App\Events\GameFinished;
use App\Events\GameStarted;
use App\Events\PlayerAnswered;
use App\Events\QuestionRevealed;
use App\Events\QuestionStarted;
use App\Events\ScoreboardUpdated;
use App\Livewire\Host\HostGame;
use App\Livewire\Host\HostResults;
use App\Livewire\Player\PlayerGame;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Event::fake([GameStarted::class, QuestionStarted::class, PlayerAnswered::class, QuestionRevealed::class, ScoreboardUpdated::class, GameFinished::class]);
    Queue::fake();
});

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

describe('the toggle', function () {
    it('is a button that starts off, with a name and an aria-pressed state', function () {
        $html = Blade::render('<x-lamma.sound-toggle />');

        expect($html)->toContain('<button')->toContain('aria-pressed="false"')->toContain('aria-label="Sounds"')->toContain('data-test="sound-toggle"')
            ->toContain('lammaSoundToggle(')->toContain('/sounds')->toContain('wire:ignore')
            ->and($html)->toContain('x-bind:aria-pressed');
    });

    it('speaks Arabic in Arabic', function () {
        app()->setLocale('ar');

        expect(Blade::render('<x-lamma.sound-toggle />'))->toContain('aria-label="الأصوات"');
    });

    it('has a variant for the navy results screen', function () {
        expect(Blade::render('<x-lamma.sound-toggle :on-dark="true" />'))->toContain('bg-navy-700')->not->toContain('bg-white');
    });

    it('is on every host game screen and on the results screen', function () {
        $room = gameRoom(players: 1, settings: ['hostScreenLocale' => HostScreenLocale::En]);
        startGame($room);

        Livewire::actingAs($room->host)->test(HostGame::class, ['room' => $room])->assertSeeHtml('data-test="sound-toggle"');
        Livewire::actingAs($room->host)->test(HostResults::class, ['room' => finishedGame(['Ali' => 100])])->assertSeeHtml('data-test="sound-toggle"');
    });

    it('is not on the phones', function () {
        $room = gameRoom(players: 1);
        $room->players()->first()->update(['guest_token' => str_repeat('s', 64), 'locale' => 'en']);
        startGame($room);

        Livewire::withCookie('lamma_guest', str_repeat('s', 64))->test(PlayerGame::class, ['room' => $room])->assertDontSeeHtml('sound-toggle')->assertDontSeeHtml('$store.sound');
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

    it('plays the podium fanfare when the results appear', function () {
        Livewire::actingAs(($room = finishedGame(['Ali' => 100]))->host)->test(HostResults::class, ['room' => $room])->assertSeeHtml("\$store.sound.play('podium')");
    });

    it('ticks for the last five seconds of a question only, not of the pause before the next one', function () {
        $js = file_get_contents(resource_path('js/lamma.js'));

        expect($js)->toContain('lamma-tick')->toContain('ticks && s <= 5 && s > 0')->toContain("Alpine.store('sound'")->toContain("localStorage.getItem('lamma:sound') === '1'")
            ->toContain('enabled: false');
        expect(file_get_contents(resource_path('views/components/lamma/timer-ring.blade.php')))->toContain(', true)"');
        expect(file_get_contents(resource_path('views/livewire/host/host-game.blade.php')))->toContain("''".')" data-test="next-countdown"');
    });
});
