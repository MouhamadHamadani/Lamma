<?php

use App\Game\RoomCodeGenerator;
use App\Models\Room;

/** A generator that hands out the given candidates in order instead of random ones. */
function generatorReturning(string ...$candidates): RoomCodeGenerator
{
    return new class($candidates) extends RoomCodeGenerator
    {
        public function __construct(private array $queue) {}

        protected function randomCode(): string
        {
            // the last candidate repeats forever
            return count($this->queue) > 1 ? array_shift($this->queue) : $this->queue[0];
        }
    };
}

it('generates 6-character codes from the allowed alphabet only', function () {
    $generator = new RoomCodeGenerator;

    foreach (range(1, 300) as $_) {
        expect($generator->generate())->toMatch('/^['.preg_quote(RoomCodeGenerator::ALPHABET, '/').']{6}$/');
    }
});

it('never uses look-alike characters', function () {
    expect(RoomCodeGenerator::ALPHABET)->not->toMatch('/[0OIL1]/');
});

it('returns unique codes that can all be stored', function () {
    $generator = new RoomCodeGenerator;
    $codes = [];

    foreach (range(1, 100) as $_) {
        $code = $generator->generate();
        Room::factory()->create(['code' => $code]); // the unique index would throw on a duplicate
        $codes[] = $code;
    }

    expect(array_unique($codes))->toHaveCount(100);
});

it('skips codes that are already taken', function () {
    Room::factory()->create(['code' => 'AAAAAA']);

    expect(generatorReturning('AAAAAA', 'AAAAAA', 'BBBBBB')->generate())->toBe('BBBBBB');
});

it('gives up instead of looping forever when every candidate is taken', function () {
    Room::factory()->create(['code' => 'AAAAAA']);

    generatorReturning('AAAAAA')->generate();
})->throws(RuntimeException::class, 'unique room code');
