<?php

use App\Enums\RoomStatus;
use App\Events\GameFinished;
use App\Events\GameStarted;
use App\Events\PlayerAnswered;
use App\Events\PlayerJoined;
use App\Events\QuestionRevealed;
use App\Events\QuestionStarted;
use App\Events\RoomRestarted;
use App\Events\ScoreboardUpdated;
use App\Game\GameEngine;
use App\Game\GameStartException;
use App\Game\PlayerStats;
use App\Game\RoomManager;
use App\Game\RoomRoster;
use App\Livewire\Host\HostGame;
use App\Livewire\Host\HostLobby;
use App\Livewire\Player\JoinRoom;
use App\Livewire\Player\PlayerGame;
use App\Livewire\Player\PlayerLobby;
use App\Livewire\Player\PlayerResults;
use App\Models\Room;
use App\Models\RoomPlayer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * The host's SCREEN never plays (no row, no answers); the host's PHONE joins like anyone else, as a guest or logged in as the host. The presence side is in
 * tests/Feature/Broadcasting/ChannelAuthTest.php ("the host on their own phone").
 */

const HOST_PHONE_TOKEN = 'p0p0p0p0p0p0p0p0p0p0p0p0p0p0p0p0p0p0p0p0p0p0p0p0p0p0p0p0p0p0p0p0';

beforeEach(function () {
    Event::fake([GameStarted::class, QuestionStarted::class, PlayerAnswered::class, QuestionRevealed::class, ScoreboardUpdated::class, GameFinished::class, PlayerJoined::class, RoomRestarted::class]);
    Queue::fake();
    at('12:00:00.000');
});

/** The host's phone joins through the page, logged in as the host unless told otherwise. Returns its row. */
function hostPhoneJoins(Room $room, bool $loggedIn = true): RoomPlayer
{
    if ($loggedIn) {
        test()->actingAs($room->host);
    }

    Livewire::withCookie('lamma_guest', HOST_PHONE_TOKEN)->test(JoinRoom::class)
        ->set('code', $room->code)->set('nickname', 'Hadi')->set('language', 'en')->call('join')
        ->assertHasNoErrors()->assertRedirect(route('play', ['room' => $room->code]));

    return $room->players()->where('guest_token', HOST_PHONE_TOKEN)->sole();
}

function hostPhone(string $component, Room $room): Testable
{
    Auth::guard('player')->forgetUser();

    return Livewire::withCookie('lamma_guest', HOST_PHONE_TOKEN)->test($component, ['room' => $room]);
}

describe('joining from the host\'s phone', function () {
    it('works logged in as the host: a normal row, saved to the account', function () {
        $room = Room::factory()->create();

        $row = hostPhoneJoins($room);

        expect($row)->user_id->toBe($room->host->id)->guest_token->toBe(HOST_PHONE_TOKEN)->nickname->toBe('Hadi')->score->toBe(0)->is_ready->toBeFalse()
            ->and($row->savedToAccount()->whereKey($row->id)->exists())->toBeTrue();
    });

    it('works as a guest on a phone that is not logged in', function () {
        $room = Room::factory()->create();

        $row = hostPhoneJoins($room, loggedIn: false);

        expect($row)->user_id->toBeNull()->guest_token->toBe(HOST_PHONE_TOKEN);
    });

    it('gives the row back on a second join, never a second player', function () {
        $room = Room::factory()->create();
        $row = hostPhoneJoins($room);

        expect(hostPhoneJoins($room)->id)->toBe($row->id)->and($room->players()->count())->toBe(1);
    });

    it('lets them in only to a lobby, like anyone else', function () {
        $room = gameRoom(players: 1, questions: 2);
        startGame($room);
        test()->actingAs($room->host);

        Livewire::withCookie('lamma_guest', HOST_PHONE_TOKEN)->test(JoinRoom::class)
            ->set('code', $room->code)->set('nickname', 'Hadi')->set('language', 'en')->call('join')
            ->assertHasErrors('code');
    });

    it('keeps the screen a non-player: nobody is added until the phone joins, and the big screen stays the host', function () {
        $room = Room::factory()->create();
        test()->actingAs($room->host);

        $this->get(route('host.lobby', ['room' => $room->code]))->assertOk();
        expect($room->players()->count())->toBe(0);

        hostPhoneJoins($room);

        $this->get(route('host.lobby', ['room' => $room->code]))->assertOk()->assertSee('data-test="start-button"', false);
    });

    it('says so on the host lobby, in both languages', function () {
        $room = Room::factory()->create();

        Livewire::actingAs($room->host)->test(HostLobby::class, ['room' => $room])->assertSeeHtml('data-test="host-can-play"')->assertSee('Want to play too? Join from your phone with this code.');

        $arabic = Room::factory()->create(['settings' => ['host_screen_locale' => 'ar']]);
        Livewire::actingAs($arabic->host)->test(HostLobby::class, ['room' => $arabic])->assertSee('تريد اللعب أيضًا؟ انضم من هاتفك بهذا الرمز.');
    });
});

describe('on the phone', function () {
    it('shows the player screens and none of the host\'s controls, in the lobby and in the game', function () {
        $room = gameRoom(players: 1, questions: 2);
        hostPhoneJoins($room);

        $lobby = hostPhone(PlayerLobby::class, $room)->html();
        foreach (['start-button', 'close-room', 'remove-player', 'remove-photo', 'qr-code'] as $control) {
            expect($lobby)->not->toContain("data-test=\"{$control}\"");
        }

        $room->players()->update(['is_ready' => true, 'left_at' => null]);
        startGame($room);

        $question = hostPhone(PlayerGame::class, $room)->assertSeeHtml('data-phase="question"')->html();
        foreach (['skip-timer', 'close-room', 'next-button', 'extend-vote', 'skip-vote'] as $control) {
            expect($question)->not->toContain("data-test=\"{$control}\"");
        }

        app(GameEngine::class)->reveal($room, 1, force: true);

        expect(hostPhone(PlayerGame::class, $room)->html())->not->toContain('data-test="next-button"');
    });

    it('has no host action to call: the phone components do not have them', function () {
        foreach (['start', 'skipTimer', 'next', 'closeRoom', 'removePlayer', 'extendVote', 'skipVote'] as $action) {
            expect(method_exists(PlayerLobby::class, $action))->toBeFalse()->and(method_exists(PlayerGame::class, $action))->toBeFalse();
        }
    });
});

describe('a whole game with the host playing from their phone', function () {
    it('needs their Ready like anyone\'s, scores them like anyone, and keeps the big screen out of the answers', function () {
        $room = gameRoom(players: 1, questions: 2);
        $row = hostPhoneJoins($room);
        $other = $room->players()->where('id', '!=', $row->id)->sole();

        // Connected but not ready: the host's own phone holds up the start.
        $row->update(['left_at' => null, 'is_ready' => false]);
        expect(fn () => app(GameEngine::class)->start($room, $room->host))->toThrow(GameStartException::class);

        $row->update(['is_ready' => true]);
        startGame($room);

        foreach ([1, 2] as $position) {
            $question = $room->fresh()->currentQuestion();
            hostPhone(PlayerGame::class, $room)->call('answer', optionOf($question));
            answer($other, $question, false);
            expect($question->fresh()->revealed_at)->not->toBeNull();

            // The big screen shows the same reveal, with its controls, and is not a player.
            Livewire::actingAs($room->host)->test(HostGame::class, ['room' => $room])->assertSeeHtml('data-test="next-button"');
            app(GameEngine::class)->advance($room, $position, force: true);
        }

        expect($room->fresh()->status)->toBe(RoomStatus::Finished)
            ->and($row->fresh()->score)->toBe(200 + 400)
            ->and($room->players()->count())->toBe(2);

        hostPhone(PlayerResults::class, $room)->assertSeeHtml('data-state="saved"');
        expect(app(PlayerStats::class)->totals($room->host))->played->toBe(1)->wins->toBe(1);
    });

    it('follows Play again: the same account and device in the new room', function () {
        $room = gameRoom(players: 1, questions: 1);
        $row = hostPhoneJoins($room);
        $row->update(['left_at' => null, 'is_ready' => true]);
        startGame($room);
        app(GameEngine::class)->reveal($room, 1, force: true);
        app(GameEngine::class)->advance($room, 1, force: true);

        $new = app(RoomManager::class)->playAgain($room->fresh(), $room->host);

        expect($new->players()->where('nickname', 'Hadi')->sole())->user_id->toBe($room->host->id)->guest_token->toBe(HOST_PHONE_TOKEN);
    });

    it('lets the big screen take the phone out, like any player', function () {
        $room = Room::factory()->create();
        $row = hostPhoneJoins($room);

        app(RoomRoster::class)->remove($room, $room->host, $row->id);

        expect($room->players()->count())->toBe(0);
    });
});

describe('the big screen\'s presence bookkeeping', function () {
    it('marks the phone connected from its member info, so the lobby does not drop it', function () {
        $room = Room::factory()->create();
        $row = hostPhoneJoins($room);
        expect($row->left_at)->not->toBeNull();

        $page = Livewire::actingAs($room->host)->test(HostLobby::class, ['room' => $room]);
        $page->call('presenceJoining', ['room_player_id' => $row->id, 'nickname' => 'Hadi', 'is_host' => false]);
        expect($row->fresh()->left_at)->toBeNull();

        $this->travel(5)->minutes();
        $page->call('pruneDisconnected');
        expect($row->fresh())->not->toBeNull();

        // The big screen's own member (no player id) changes nothing, arriving or leaving.
        $page->call('presenceLeaving', ['room_player_id' => null, 'nickname' => $room->host->name, 'is_host' => true]);
        expect($row->fresh()->left_at)->toBeNull();

        $page->call('presenceLeaving', ['room_player_id' => $row->id, 'nickname' => 'Hadi', 'is_host' => false]);
        expect($row->fresh()->left_at)->not->toBeNull();
    });
});
