<?php

use App\Enums\HostScreenLocale;
use App\Events\GameFinished;
use App\Events\GameStarted;
use App\Events\PlayerAnswered;
use App\Events\QuestionRevealed;
use App\Events\QuestionStarted;
use App\Events\ScoreboardUpdated;
use App\Game\GameEngine;
use App\Game\Podium;
use App\Game\Scoreboard;
use App\Livewire\Host\HostGame;
use App\Livewire\Host\HostLobby;
use App\Livewire\Host\HostResults;
use App\Livewire\Player\PlayerLobby;
use App\Livewire\Player\PlayerResults;
use App\Models\Room;
use App\Models\RoomPlayer;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

const PHOTO_TOKEN = 'ph0t0000000000000000000000000000000000000000000000000000000000ab';

beforeEach(function () {
    Event::fake([GameStarted::class, QuestionStarted::class, PlayerAnswered::class, QuestionRevealed::class, ScoreboardUpdated::class, GameFinished::class]);
    Queue::fake();
});

/** Give every player of the room a selfie; returns player id => URL. */
function selfies(Room $room): array
{
    return $room->players()->orderBy('id')->get()->mapWithKeys(function (RoomPlayer $player) {
        photos()->set($player, upload(jpegBytes(120, 120)));

        return [$player->id => $player->fresh()->avatarUrl()];
    })->all();
}

/** How many <img> elements with this URL are on the page. */
function imagesOf(string $html, string $url): int
{
    return substr_count($html, 'src="'.$url.'"');
}

describe('event payloads', function () {
    it('carry avatar_url for every player they mention when the room has photos on', function () {
        $room = gameRoom(players: 3, questions: 1);
        $urls = selfies($room);
        $first = startGame($room);
        $players = $room->players()->orderBy('id')->get();
        answer($players[0], $first, true);
        answer($players[1], $first, false);
        app(GameEngine::class)->reveal($room, 1, force: true);
        app(GameEngine::class)->advance($room, 1, force: true);

        Event::assertDispatched(PlayerAnswered::class, fn (PlayerAnswered $e) => $e->broadcastWith()['player']['avatar_url'] === $urls[$e->broadcastWith()['player']['id']]);
        Event::assertDispatched(QuestionRevealed::class, function (QuestionRevealed $e) use ($urls) {
            $picked = collect($e->broadcastWith()['picks'])->pluck('players')->flatten(1);

            return $picked->count() === 2 && $picked->every(fn (array $p) => $p['avatar_url'] === $urls[$p['id']]);
        });
        foreach ([ScoreboardUpdated::class, GameFinished::class] as $event) {
            Event::assertDispatched($event, fn ($e) => collect($e->broadcastWith()['ranking'])->every(fn (array $row) => $row['player']['avatar_url'] === $urls[$row['player']['id']]));
        }
    });

    it('send no avatar_url at all when the room has photos off', function () {
        $room = gameRoom(players: 3, questions: 1, settings: ['showPhotos' => false]);
        $first = startGame($room);
        answer($room->players()->orderBy('id')->first(), $first, true);
        app(GameEngine::class)->reveal($room, 1, force: true);
        app(GameEngine::class)->advance($room, 1, force: true);

        foreach ([PlayerAnswered::class, QuestionRevealed::class, ScoreboardUpdated::class, GameFinished::class] as $event) {
            Event::assertDispatched($event, fn ($e) => ! str_contains(json_encode($e->broadcastWith()), 'avatar_url'));
        }
        expect(json_encode((new Scoreboard)->ranking($room)))->not->toContain('avatar_url');
    });

    it('never carry a guest token, an email or the file path', function () {
        $room = gameRoom(players: 2, questions: 1);
        $room->players()->orderBy('id')->first()->update(['guest_token' => PHOTO_TOKEN]);
        $room->players()->orderBy('id')->get()->each(fn (RoomPlayer $player) => photos()->set($player, upload(jpegBytes(80, 80))));
        $first = startGame($room);
        answer($room->players()->orderBy('id')->first(), $first, true);
        app(GameEngine::class)->reveal($room, 1, force: true);

        Event::assertDispatched(QuestionRevealed::class, fn ($e) => ! str_contains($raw = json_encode($e->broadcastWith()), PHOTO_TOKEN) && ! str_contains($raw, 'guest_token') && ! str_contains($raw, 'email') && ! str_contains($raw, 'avatar_path'));
        Event::assertDispatched(ScoreboardUpdated::class, fn ($e) => ! str_contains($raw = json_encode($e->broadcastWith()), PHOTO_TOKEN) && ! str_contains($raw, 'avatar_path'));
    });

    it('rank with photos: the ranking and the podium keep the photo of each player', function () {
        $room = finishedGame(['Ali' => 800, 'Sara' => 800, 'Maya' => 500]);
        $urls = selfies($room);

        $ranking = (new Scoreboard)->ranking($room);
        $steps = (new Podium)->steps($ranking);

        expect(collect($ranking)->pluck('player.avatar_url')->all())->each->toBeIn($urls)
            ->and(collect($steps)->pluck('players')->flatten(1)->every(fn (array $p) => $p['avatar_url'] === $urls[$p['id']]))->toBeTrue()
            ->and(collect((new Podium)->winners($ranking))->pluck('avatar_url')->filter()->count())->toBe(2);
    });
});

describe('the host screens', function () {
    it('shows photos on the lobby rows, loaded at once, with the initial underneath as a fallback', function () {
        $room = Room::factory()->create();
        $with = RoomPlayer::factory()->for($room)->create(['nickname' => 'Sara']);
        $without = RoomPlayer::factory()->for($room)->create(['nickname' => 'Ben']);
        photos()->set($with, upload(jpegBytes(120, 120)));
        $url = $with->fresh()->avatarUrl();

        $html = Livewire::actingAs($room->host)->test(HostLobby::class, ['room' => $room])->html();

        expect(imagesOf($html, $url))->toBe(1)->and($html)->toContain('alt="Sara"')->not->toContain('alt="Ben"')
            ->and(substr_count($html, 'onerror="this.remove()"'))->toBe(1)->and($html)->toContain('loading="eager"');
    });

    it('refreshes the lobby when a player changes their name or photo', function () {
        $room = Room::factory()->create();

        $listeners = Livewire::actingAs($room->host)->test(HostLobby::class, ['room' => $room])->instance()->getListeners();

        expect($listeners)->toHaveKey("echo-presence:room.{$room->code},PlayerUpdated", '$refresh');
    });

    it('shows photos on the question screen ("2 of 3 answered") and on the reveal: pickers and scoreboard', function () {
        $room = gameRoom(players: 3, questions: 2, settings: ['hostScreenLocale' => HostScreenLocale::En]);
        $urls = selfies($room);
        $first = startGame($room);
        $players = $room->players()->orderBy('id')->get();
        $page = Livewire::actingAs($room->host)->test(HostGame::class, ['room' => $room]);

        $html = $page->html();
        foreach ($urls as $url) {
            expect(imagesOf($html, $url))->toBe(1);
        }

        answer($players[0], $first, true);
        answer($players[1], $first, false);
        app(GameEngine::class)->reveal($room, 1, force: true);
        $html = Livewire::actingAs($room->host)->test(HostGame::class, ['room' => $room])->html();

        // a player who picked an answer shows twice (the tile and the scoreboard), one who did not shows once (the scoreboard)
        expect(imagesOf($html, $urls[$players[0]->id]))->toBe(2)->and(imagesOf($html, $urls[$players[1]->id]))->toBe(2)->and(imagesOf($html, $urls[$players[2]->id]))->toBe(1);
    });

    it('shows photos on the podium, the biggest on the winner, and builds them from the database on a reload', function () {
        $room = finishedGame(['Ali' => 800, 'Sara' => 700, 'Maya' => 500]);
        $urls = selfies($room);
        $ali = $room->players()->where('nickname', 'Ali')->first();

        $page = Livewire::actingAs($room->host)->test(HostResults::class, ['room' => $room]);
        $reloaded = Livewire::actingAs($room->host)->test(HostResults::class, ['room' => $room]);

        foreach ([$page->html(), $reloaded->html()] as $html) {
            foreach ($urls as $url) {
                expect(imagesOf($html, $url))->toBe(1);
            }
            // the winner's avatar is the biggest (104px, the others 84px) and wears the winner's photo
            $biggest = explode('width: 104px; height: 104px', $html);
            expect($biggest)->toHaveCount(2)->and(substr($biggest[1], 0, 700))->toContain($urls[$ali->id]);
        }
    });

    it('shows a plain initial for a player without a photo, with nothing broken', function () {
        $room = finishedGame(['Ali' => 800, 'Sara' => 700]);

        $html = Livewire::actingAs($room->host)->test(HostResults::class, ['room' => $room])->html();

        expect($html)->not->toContain('onerror="this.remove()"')->toContain('<span aria-hidden="true">A</span>');
    });

    it('shows no photo anywhere when the room has photos off', function () {
        $room = finishedGame(['Ali' => 800, 'Sara' => 700], settings: ['showPhotos' => false]);

        $html = Livewire::actingAs($room->host)->test(HostResults::class, ['room' => $room])->html();

        expect($html)->not->toContain('onerror="this.remove()"')->not->toContain('avatar_url');
    });
});

describe('the phones', function () {
    it('shows photos in the lobby list and on the avatar, loaded at once', function () {
        $room = Room::factory()->create();
        $me = RoomPlayer::factory()->for($room)->create(['guest_token' => PHOTO_TOKEN, 'nickname' => 'Sara', 'locale' => 'en']);
        $other = RoomPlayer::factory()->for($room)->create(['nickname' => 'Ben']);
        photos()->set($me, upload(jpegBytes(120, 120)));
        photos()->set($other, upload(jpegBytes(120, 120)));

        $html = Livewire::withCookie('lamma_guest', PHOTO_TOKEN)->test(PlayerLobby::class, ['room' => $room])->html();

        expect(imagesOf($html, $me->fresh()->avatarUrl()))->toBe(2)->and(imagesOf($html, $other->fresh()->avatarUrl()))->toBe(1)
            ->and($html)->toContain('alt="Sara"')->toContain('alt="Ben"')->and(substr_count($html, 'loading="lazy"'))->toBe(0);
    });

    it('shows photos in the results leaderboard', function () {
        $room = finishedGame(['Ali' => 800, 'Sara' => 700]);
        $urls = selfies($room);
        $room->players()->where('nickname', 'Sara')->update(['guest_token' => PHOTO_TOKEN, 'locale' => 'en']);

        $html = Livewire::withCookie('lamma_guest', PHOTO_TOKEN)->test(PlayerResults::class, ['room' => $room])->html();

        foreach ($urls as $url) {
            expect(imagesOf($html, $url))->toBe(1);
        }
    });

    it('shows no photo UI in a room with photos off', function () {
        $room = Room::factory()->create(['settings' => ['show_photos' => false]]);
        RoomPlayer::factory()->for($room)->create(['guest_token' => PHOTO_TOKEN, 'nickname' => 'Sara', 'locale' => 'en']);

        $html = Livewire::withCookie('lamma_guest', PHOTO_TOKEN)->test(PlayerLobby::class, ['room' => $room])->html();

        expect($html)->not->toContain('onerror="this.remove()"')->not->toContain('data-test="selfie"')->not->toContain('Take a selfie');
    });
});

describe('the avatar fallback', function () {
    it('keeps the screen reader reading the name once: the image has the nickname as alt, the initial under it is hidden', function () {
        $player = RoomPlayer::factory()->create(['nickname' => 'Sara']);
        photos()->set($player, upload(jpegBytes(80, 80)));

        $html = view('components.lamma.avatar', ['name' => 'Sara', 'photo' => $player->fresh()->avatarUrl()])->render();

        expect($html)->toContain('alt="Sara"')->toContain('<span aria-hidden="true">S</span>');
    });
});
