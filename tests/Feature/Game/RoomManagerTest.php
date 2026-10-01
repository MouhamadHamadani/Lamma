<?php

use App\Enums\Difficulty;
use App\Enums\HostScreenLocale;
use App\Enums\RoomStatus;
use App\Game\NotEnoughQuestions;
use App\Game\QuestionPool;
use App\Game\RoomCodeGenerator;
use App\Game\RoomManager;
use App\Game\RoomSettings;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

function settingsFor(int ...$categoryIds): RoomSettings
{
    return new RoomSettings(categoryIds: $categoryIds, questionCount: 5, secondsPerQuestion: 30, difficulty: Difficulty::Hard, hostScreenLocale: HostScreenLocale::Ar);
}

it('creates a lobby with the code, host and settings', function () {
    $host = User::factory()->create();
    $category = categoryWithQuestions(6, Difficulty::Hard);

    $room = app(RoomManager::class)->create($host, settingsFor($category->id));

    expect($room->code)->toMatch('/^['.RoomCodeGenerator::ALPHABET.']{6}$/')
        ->and($room->host_id)->toBe($host->id)
        ->and($room->status)->toBe(RoomStatus::Lobby)
        ->and($room->fresh()->settings)->toEqual(settingsFor($category->id));
});

it('refuses a game the questions cannot fill, and creates nothing', function () {
    $host = User::factory()->create();
    $category = categoryWithQuestions(3, Difficulty::Hard);

    try {
        app(RoomManager::class)->create($host, settingsFor($category->id));
        $this->fail('Expected NotEnoughQuestions');
    } catch (NotEnoughQuestions $e) {
        expect($e->available)->toBe(3)->and($e->needed)->toBe(5);
    }

    expect(Room::count())->toBe(0);
});

it('refuses an empty category choice', function () {
    categoryWithQuestions(10);

    expect(fn () => app(RoomManager::class)->create(User::factory()->create(), settingsFor()))->toThrow(NotEnoughQuestions::class);
});

describe('one active room per host', function () {
    it('closes the host\'s earlier lobby and playing rooms when a new one is created', function () {
        $host = User::factory()->create();
        $category = categoryWithQuestions(6, Difficulty::Hard);
        $lobby = Room::factory()->for($host, 'host')->create();
        $playing = Room::factory()->for($host, 'host')->playing()->create();

        $new = app(RoomManager::class)->create($host, settingsFor($category->id));

        expect($lobby->fresh()->status)->toBe(RoomStatus::Finished)->and($lobby->fresh()->finished_at)->not->toBeNull()
            ->and($playing->fresh()->status)->toBe(RoomStatus::Finished)
            ->and($new->fresh()->status)->toBe(RoomStatus::Lobby)
            ->and(Room::where('host_id', $host->id)->active()->count())->toBe(1);
    });

    it('leaves other hosts\' rooms and already finished rooms alone', function () {
        $host = User::factory()->create();
        $category = categoryWithQuestions(6, Difficulty::Hard);
        $others = Room::factory()->create();
        $done = Room::factory()->for($host, 'host')->finished()->create(['finished_at' => now()->subDay()]);

        app(RoomManager::class)->create($host, settingsFor($category->id));

        expect($others->fresh()->status)->toBe(RoomStatus::Lobby)
            ->and($done->fresh()->finished_at->lt(now()->subHours(12)))->toBeTrue(); // not stamped again
    });

    it('keeps the old room when the new one is refused', function () {
        $host = User::factory()->create();
        $old = Room::factory()->for($host, 'host')->create();
        $category = categoryWithQuestions(1, Difficulty::Hard);

        expect(fn () => app(RoomManager::class)->create($host, settingsFor($category->id)))->toThrow(NotEnoughQuestions::class);

        expect($old->fresh()->status)->toBe(RoomStatus::Lobby);
    });

    it('closes a room by hand', function () {
        $room = Room::factory()->create();

        app(RoomManager::class)->close($room);

        expect($room->fresh()->status)->toBe(RoomStatus::Finished)->and($room->fresh()->finished_at)->not->toBeNull();
    });
});

describe('room codes', function () {
    it('tries again when the code was taken in the meantime', function () {
        $host = User::factory()->create();
        $category = categoryWithQuestions(6, Difficulty::Hard);
        Room::factory()->create(['code' => 'AAAAAA']);

        $codes = Mockery::mock(RoomCodeGenerator::class);
        $codes->shouldReceive('generate')->andReturn('AAAAAA', 'BBBBBB');

        $room = (new RoomManager($codes, app(QuestionPool::class)))->create($host, settingsFor($category->id));

        expect($room->code)->toBe('BBBBBB');
    });

    it('gives up after three collisions', function () {
        $host = User::factory()->create();
        $category = categoryWithQuestions(6, Difficulty::Hard);
        Room::factory()->create(['code' => 'AAAAAA']);

        $codes = Mockery::mock(RoomCodeGenerator::class);
        $codes->shouldReceive('generate')->times(3)->andReturn('AAAAAA');

        expect(fn () => (new RoomManager($codes, app(QuestionPool::class)))->create($host, settingsFor($category->id)))
            ->toThrow(UniqueConstraintViolationException::class);
    });
});
