<?php

use App\Enums\RoomStatus;
use App\Events\GameFinished;
use App\Events\GameStarted;
use App\Events\RoomClosed;
use App\Events\RoomRestarted;
use App\Game\NotEnoughQuestions;
use App\Game\Podium;
use App\Game\RoomManager;
use App\Game\Scoreboard;
use App\Models\Question;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Event::fake([GameStarted::class, GameFinished::class, RoomRestarted::class, RoomClosed::class]);
    Queue::fake();
});

/** A final ranking the way Scoreboard builds it: score descending, ties share a rank (1, 1, 3). */
function ranked(array $scores): array
{
    return app(Scoreboard::class)->ranking(finishedGame($scores));
}

describe('the ranking with ties', function () {
    it('gives players with the same score the same rank, and skips the ranks they took', function () {
        $ranking = ranked(['Ali' => 800, 'Sara' => 800, 'Maya' => 500, 'Omar' => 500, 'Nour' => 100]);

        expect(collect($ranking)->pluck('rank', 'player.nickname')->all())->toBe(['Ali' => 1, 'Sara' => 1, 'Maya' => 3, 'Omar' => 3, 'Nour' => 5]);
    });

    it('puts the earlier joiner first among equals', function () {
        $ranking = ranked(['Ali' => 800, 'Sara' => 800]);

        expect(collect($ranking)->pluck('player.nickname')->all())->toBe(['Ali', 'Sara']);
    });
});

describe('the podium steps', function () {
    it('has a step per rank from 1 to 3 that somebody stands on', function () {
        $steps = (new Podium)->steps(ranked(['Ali' => 800, 'Sara' => 700, 'Maya' => 500, 'Omar' => 100]));

        expect(collect($steps)->pluck('rank')->all())->toBe([1, 2, 3])
            ->and(collect($steps[0]['players'])->pluck('nickname')->all())->toBe(['Ali'])
            ->and($steps[0]['players'][0]['total'])->toBe(800);
    });

    it('shares a step between tied players and skips the ranks they took', function () {
        $steps = (new Podium)->steps(ranked(['Ali' => 800, 'Sara' => 800, 'Maya' => 500]));

        expect(collect($steps)->pluck('rank')->all())->toBe([1, 3])
            ->and(collect($steps[0]['players'])->pluck('nickname')->all())->toBe(['Ali', 'Sara']);
    });

    it('has fewer steps with fewer than three players', function () {
        expect((new Podium)->steps(ranked(['Ali' => 300])))->toHaveCount(1);
        expect((new Podium)->steps(ranked(['Ali' => 300, 'Sara' => 200])))->toHaveCount(2);
        expect((new Podium)->steps([]))->toBe([]);
    });

    it('names the winners: everyone on the top score, but nobody if that score is nothing', function () {
        expect(collect((new Podium)->winners(ranked(['Ali' => 800, 'Sara' => 800, 'Maya' => 500])))->pluck('nickname')->all())->toBe(['Ali', 'Sara']);
        expect((new Podium)->winners(ranked(['Ali' => 0, 'Sara' => 0])))->toBe([]);
    });
});

describe('ordinals', function () {
    it('writes 1st, 2nd, 3rd, 4th and 11th to 13th in English, and plain numbers in Arabic', function () {
        expect(collect([1, 2, 3, 4, 11, 12, 13, 21, 22, 101, 111])->map(fn (int $n) => Scoreboard::ordinal($n))->all())
            ->toBe(['1st', '2nd', '3rd', '4th', '11th', '12th', '13th', '21st', '22nd', '101st', '111th']);

        app()->setLocale('ar');
        expect(Scoreboard::ordinal(2))->toBe('2')->and(Scoreboard::suffix(2))->toBe('');
    });
});

describe('a game that ran to the end', function () {
    it('is finished with every question revealed', function () {
        $room = finishedGame(['Ali' => 100]);

        expect($room->isCompleted())->toBeTrue()->and(Room::completed()->whereKey($room->id)->exists())->toBeTrue();
    });

    it('is not a room closed before it began, or part-way, or still being played', function () {
        $lobby = Room::factory()->create(['status' => RoomStatus::Finished]);
        $partway = finishedGame(['Ali' => 100]);
        $partway->roomQuestions()->where('position', 3)->update(['revealed_at' => null]);
        $playing = gameRoom(players: 1);
        startGame($playing);

        foreach ([$lobby, $partway->fresh(), $playing->fresh()] as $room) {
            expect($room->isCompleted())->toBeFalse()->and(Room::completed()->whereKey($room->id)->exists())->toBeFalse();
        }
    });
});

describe('Play again (RoomManager::playAgain)', function () {
    it('copies the connected players with the same identity, so every phone finds its row in the new room', function () {
        $room = finishedGame(['Ali' => 800, 'Sara' => 700]);
        $user = User::factory()->create();
        $account = RoomPlayer::factory()->for($room)->forUser($user)->create(['nickname' => 'Account', 'score' => 300, 'left_at' => null]);
        $ali = $room->players()->where('nickname', 'Ali')->first();

        $new = app(RoomManager::class)->playAgain($room, $room->host);

        $copy = $new->players()->where('nickname', 'Ali')->first();
        expect($copy->guest_token)->toBe($ali->guest_token)->and($copy->user_id)->toBeNull()->and($copy->locale)->toBe($ali->locale)->and($copy->id)->not->toBe($ali->id);
        expect($new->players()->where('nickname', 'Account')->value('user_id'))->toBe($user->id)->and($new->players()->where('nickname', 'Account')->value('guest_token'))->toBeNull();
        expect($account->fresh()->room_id)->toBe($room->id); // the old rows stay where they are, with their scores
        expect($room->players()->pluck('score', 'nickname')->all())->toMatchArray(['Ali' => 800, 'Sara' => 700, 'Account' => 300]);
    });

    it('lets each phone through to the new room by its own cookie or account', function () {
        $room = finishedGame(['Ali' => 800]);
        $token = $room->players()->first()->guest_token;

        $new = app(RoomManager::class)->playAgain($room, $room->host);

        $this->withCookie('lamma_guest', $token)->get(route('play', $new->code))->assertOk();
    });

    it('starts the copies at score 0, not ready, and not connected until their phones join the new channel', function () {
        $room = finishedGame(['Ali' => 800, 'Sara' => 700]);

        $new = app(RoomManager::class)->playAgain($room, $room->host);

        $new->players->each(function (RoomPlayer $player) {
            expect($player->score)->toBe(0)->and($player->is_ready)->toBeFalse()->and($player->left_at)->not->toBeNull();
        });
        expect($new->status)->toBe(RoomStatus::Lobby)->and($new->host_id)->toBe($room->host_id)->and($new->code)->not->toBe($room->code);
    });

    it('keeps the join order', function () {
        $room = finishedGame(['Ali' => 800, 'Sara' => 700, 'Maya' => 500]);

        $new = app(RoomManager::class)->playAgain($room, $room->host);

        expect($new->players()->orderBy('joined_at')->orderBy('id')->pluck('nickname')->all())->toBe(['Ali', 'Sara', 'Maya']);
    });

    it('leaves out players who have gone', function () {
        $room = finishedGame(['Ali' => 800, 'Sara' => 700]);
        $room->players()->where('nickname', 'Sara')->update(['left_at' => now()->subMinute()]);

        $new = app(RoomManager::class)->playAgain($room, $room->host);

        expect($new->players()->pluck('nickname')->all())->toBe(['Ali']);
    });

    it('uses the same settings', function () {
        $room = finishedGame(['Ali' => 800], questions: 5, settings: ['secondsPerQuestion' => 30]);

        $new = app(RoomManager::class)->playAgain($room, $room->host);

        expect($new->settings)->toEqual($room->settings);
    });

    it('tells the old room\'s phones the new code, once', function () {
        $room = finishedGame(['Ali' => 800]);

        $new = app(RoomManager::class)->playAgain($room, $room->host);
        app(RoomManager::class)->playAgain($room, $room->host);

        Event::assertDispatchedTimes(RoomRestarted::class, 1);
        Event::assertDispatched(RoomRestarted::class, fn (RoomRestarted $e) => $e->roomCode === $room->code && $e->newCode === $new->code);
    });

    it('returns the same new room when asked again, even from a stale copy of the old one', function () {
        $room = finishedGame(['Ali' => 800]);
        $stale = Room::find($room->id);

        $first = app(RoomManager::class)->playAgain($room, $room->host);
        $second = app(RoomManager::class)->playAgain($stale, $room->host);

        expect($second->id)->toBe($first->id)->and(Room::count())->toBe(2)->and(RoomPlayer::where('room_id', $first->id)->count())->toBe(1);
    });

    it('makes a fresh room if the earlier new room has already been closed', function () {
        $room = finishedGame(['Ali' => 800]);
        $first = app(RoomManager::class)->playAgain($room, $room->host);
        app(RoomManager::class)->close($first);

        $second = app(RoomManager::class)->playAgain($room, $room->host);

        expect($second->id)->not->toBe($first->id)->and($second->status)->toBe(RoomStatus::Lobby)->and($room->fresh()->next_room_id)->toBe($second->id);
    });

    it('leaves the old finished room as it was', function () {
        $room = finishedGame(['Ali' => 800]);

        app(RoomManager::class)->playAgain($room, $room->host);

        expect($room->fresh()->status)->toBe(RoomStatus::Finished)->and($room->fresh()->isCompleted())->toBeTrue();
        Event::assertNotDispatched(RoomClosed::class, fn (RoomClosed $e) => $e->roomCode === $room->code);
    });

    it('refuses anyone but the host, and a game that has not finished', function () {
        $room = finishedGame(['Ali' => 800]);
        $lobby = Room::factory()->create();

        expect(fn () => app(RoomManager::class)->playAgain($room, User::factory()->create()))->toThrow(AuthorizationException::class);
        expect(fn () => app(RoomManager::class)->playAgain($lobby, $lobby->host))->toThrow(LogicException::class);
        expect(Room::count())->toBe(2);
    });

    it('makes nothing when there are no longer enough questions', function () {
        $room = finishedGame(['Ali' => 800]);
        Question::query()->update(['is_active' => false]);

        expect(fn () => app(RoomManager::class)->playAgain($room, $room->host))->toThrow(NotEnoughQuestions::class);

        expect(Room::count())->toBe(1)->and($room->fresh()->next_room_id)->toBeNull();
        Event::assertNotDispatched(RoomRestarted::class);
    });

    it('closes the host\'s other lobby, like creating a room does', function () {
        $room = finishedGame(['Ali' => 800]);
        $other = Room::factory()->create(['host_id' => $room->host_id]);

        app(RoomManager::class)->playAgain($room, $room->host);

        expect($other->fresh()->status)->toBe(RoomStatus::Finished);
    });
});
