<?php

use Illuminate\Support\Facades\Blade;

beforeEach(fn () => app()->setLocale('en'));

/** @return list<array{rank: int, player: array{id: int, nickname: string, locale: string}, total: int, gained: int}> */
function ranking(int $players): array
{
    return collect(range(1, $players))->map(fn (int $n) => [
        'rank' => $n, 'player' => ['id' => $n, 'nickname' => "Player{$n}", 'locale' => 'en'], 'total' => 1000 - $n * 100, 'gained' => $n === 1 ? 100 : 0,
    ])->all();
}

describe('progress dots', function () {
    it('shows done, current and upcoming questions differently', function () {
        $html = Blade::render('<x-lamma.progress-dots :total="5" :current="3" />');

        expect(substr_count($html, '<li'))->toBe(5)
            ->and(substr_count($html, 'w-2.5 bg-navy'))->toBe(2)
            ->and(substr_count($html, 'w-6 bg-coral'))->toBe(1)
            ->and(substr_count($html, 'w-2.5 bg-line'))->toBe(2)
            ->and($html)->toContain('aria-hidden="true"');
    });

    it('is all done once the game is over', function () {
        $html = Blade::render('<x-lamma.progress-dots :total="3" :current="4" />');

        expect(substr_count($html, 'w-2.5 bg-navy'))->toBe(3)->and($html)->not->toContain('bg-coral');
    });
});

describe('scoreboard', function () {
    it('lists the players best first with their totals and the points gained, names left to right', function () {
        $html = Blade::render('<x-lamma.scoreboard :ranking="$ranking" />', ['ranking' => ranking(3)]);

        expect($html)->toContain('Scoreboard')->toContain('<bdi dir="ltr">Player1</bdi>')->toContain('lammaCountUp(100)')->toContain('lammaCountUp(0)');
        expect(substr_count($html, 'data-test="total"'))->toBe(3)->and($html)->toMatch('/Player1.*Player2.*Player3/s');
    });

    it('lifts the leader and shows their rank in sun', function () {
        $html = Blade::render('<x-lamma.scoreboard :ranking="$ranking" />', ['ranking' => ranking(2)]);

        expect($html)->toContain('bg-navy-600')->toContain('text-sun');
    });

    it('slides each row from where it stood before: the distance is the places moved', function () {
        // Player3 was first, Player1 third: they swap, Player2 stays.
        $html = Blade::render('<x-lamma.scoreboard :ranking="$ranking" :previous="[3 => 0, 2 => 1, 1 => 2]" />', ['ranking' => ranking(3)]);

        expect($html)->toContain('data-player="1" data-rank="1"')
            ->and($html)->toContain('--dy: calc((var(--row) + 10px) * 2)')   // Player1 moved up two places
            ->and($html)->toContain('--dy: calc((var(--row) + 10px) * 0)')
            ->and($html)->toContain('--dy: calc((var(--row) + 10px) * -2)')  // Player3 moved down two
            ->and($html)->toContain('motion-safe:animate-flip')->toContain('motion-reduce:animate-fade-in');
    });

    it('shows five rows on a short screen and more as the screen gets taller, so nothing scrolls', function () {
        $html = Blade::render('<x-lamma.scoreboard :ranking="$ranking" />', ['ranking' => ranking(12)]);

        expect(substr_count($html, '<li'))->toBe(10)                              // never more than ten
            ->and(substr_count($html, ' hidden '))->toBe(5)                        // the last five wait for a taller screen
            ->and($html)->toContain('[@media(min-height:800px)]:flex')
            ->and($html)->toContain('[@media(min-height:1060px)]:flex');
    });

    it('can leave out the points gained, for the final standings', function () {
        $html = Blade::render('<x-lamma.scoreboard :ranking="$ranking" :gained="false" />', ['ranking' => ranking(2)]);

        expect($html)->not->toContain('data-test="gained"')->not->toContain('lammaCountUp');
    });

    it('renders its footer', function () {
        $html = Blade::render('<x-lamma.scoreboard :ranking="$ranking"><button>Next question</button></x-lamma.scoreboard>', ['ranking' => ranking(1)]);

        expect($html)->toContain('<button>Next question</button>');
    });
});

describe('avatar', function () {
    it('pops the answered tick in', function () {
        expect(Blade::render('<x-lamma.avatar name="Sara" checked />'))->toContain('motion-safe:animate-chip-pop');
        expect(Blade::render('<x-lamma.avatar name="Sara" />'))->not->toContain('chip-pop');
    });
});
