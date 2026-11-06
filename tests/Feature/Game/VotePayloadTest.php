<?php

use App\Events\PlayerVoted;
use App\Events\VoteResult;
use App\Events\VoteStarted;
use App\Game\GameEngine;
use App\Models\Category;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Event::fake();
    Queue::fake();
    at('12:00:00');
});

/** A vote room whose two players are a guest (with a token) and a logged-in user (with an email), at its first vote. */
function payloadVote(bool $photos = true): array
{
    $categories = [
        categoryWithQuestions(6, category: ['name' => ['en' => 'Opening', 'ar' => 'البداية'], 'slug' => 'geography']),
        categoryWithQuestions(6, category: ['name' => ['en' => 'Science', 'ar' => 'العلوم'], 'slug' => 'science']),
        categoryWithQuestions(6, category: ['name' => ['en' => 'Sports', 'ar' => 'الرياضة'], 'slug' => 'sports']),
    ];
    $room = voteRoom(players: 0, questions: 10, every: 5, categories: $categories, settings: ['showPhotos' => $photos]);
    $guest = RoomPlayer::factory()->for($room)->create(['nickname' => 'Guesty', 'guest_token' => str_repeat('g', 64), 'is_ready' => true, 'left_at' => null]);
    $user = User::factory()->create(['email' => 'secret@example.com']);
    $member = RoomPlayer::factory()->for($room)->forUser($user)->create(['nickname' => 'Membr', 'is_ready' => true, 'left_at' => null]);

    return [$room, playToVote($room), $guest, $member];
}

function noSecrets(array $payload): void
{
    $json = json_encode($payload);
    expect($json)->not->toContain(str_repeat('g', 64))->not->toContain('secret@example.com')->not->toContain('guest_token')->not->toContain('email');
}

it('sends every vote event at once on the room channel, with the server time', function () {
    [$room, $round] = payloadVote();

    foreach ([new VoteStarted($room, $round), new PlayerVoted($room->code, 1, [], 0, 2), new VoteResult($room, $round)] as $event) {
        expect($event)->toBeInstanceOf(ShouldBroadcastNow::class)
            ->and($event->broadcastOn()[0]->name)->toBe('presence-room.'.$room->code)
            ->and($event->broadcastWith()['server_time'])->toBe(now()->getTimestampMs());
    }
});

it('VoteStarted: the offered categories in display order in both languages, the deadline, and no counts', function () {
    [$room, $round] = payloadVote();
    $payload = (new VoteStarted($room, $round))->broadcastWith();
    $names = Category::query()->whereIn('id', $round->option_ids)->get()->keyBy('id');

    expect($payload['vote']['round'])->toBe(1)
        ->and($payload['vote']['after_position'])->toBe(5)
        ->and(collect($payload['vote']['options'])->pluck('id')->all())->toBe($round->option_ids)
        ->and($payload['vote']['options'][0]['name'])->toBe(['ar' => $names[$round->option_ids[0]]->getTranslation('name', 'ar'), 'en' => $names[$round->option_ids[0]]->getTranslation('name', 'en')])
        ->and($payload['vote']['options'][0])->toHaveKeys(['tint', 'icon'])
        ->and($payload['vote']['ends_at'])->toBe('2026-10-01T12:00:15.000Z')
        ->and(json_encode($payload))->not->toContain('count')->not->toContain('votes')->not->toContain('players');
    noSecrets($payload);
});

it('PlayerVoted: the votes per category and how many voted, never who voted for what', function () {
    [$room, $round, $guest] = payloadVote();

    app(GameEngine::class)->castVote($guest, $round->option_ids[1]);

    Event::assertDispatched(PlayerVoted::class, function (PlayerVoted $event) use ($round, $guest) {
        $payload = $event->broadcastWith();
        noSecrets($payload);

        return $payload['counts'] === [['category_id' => $round->option_ids[0], 'votes' => 0], ['category_id' => $round->option_ids[1], 'votes' => 1]]
            && $payload['voted'] === 1 && $payload['expected'] === 2
            && ! str_contains(json_encode($payload), 'Guesty')
            && ! str_contains(json_encode($payload), '"id":'.$guest->id)
            && array_keys($payload) === ['code', 'server_time', 'round', 'counts', 'voted', 'expected'];
    });
});

it('VoteResult: the winner, the counts, who voted for each, tie and no-votes flags, and photos only when the room has them', function (bool $photos) {
    [$room, $round, $guest, $member] = payloadVote($photos);
    $engine = app(GameEngine::class);
    $engine->castVote($guest, $round->option_ids[0]);
    $engine->castVote($member, $round->option_ids[0]);

    Event::assertDispatched(VoteResult::class, function (VoteResult $event) use ($round, $guest, $member, $photos) {
        $payload = $event->broadcastWith();
        noSecrets($payload);
        $voters = $payload['voters'][0]['players'];

        return $payload['winner_id'] === $round->option_ids[0]
            && $payload['counts'][0] === ['category_id' => $round->option_ids[0], 'votes' => 2]
            && $payload['voters'][0]['category_id'] === $round->option_ids[0]
            && collect($voters)->pluck('nickname')->all() === ['Guesty', 'Membr']
            && collect($voters)->pluck('id')->all() === [$guest->id, $member->id]
            && array_key_exists('avatar_url', $voters[0]) === $photos
            && $payload['voters'][1]['players'] === []
            && $payload['was_tie'] === false && $payload['no_votes'] === false;
    });
})->with(['photos on' => true, 'photos off' => false]);

it('VoteResult says when a tie was broken at random', function () {
    [$room, $round, $guest, $member] = payloadVote();
    $engine = app(GameEngine::class);
    $engine->castVote($guest, $round->option_ids[0]);
    $engine->castVote($member, $round->option_ids[1]);

    Event::assertDispatched(VoteResult::class, fn (VoteResult $event) => $event->result['was_tie'] === true && in_array($event->result['winner_id'], array_slice($round->option_ids, 0, 2), true));
});
