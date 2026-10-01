<?php

use App\Events\GameFinished;
use App\Events\GameStarted;
use App\Events\RoomRestarted;
use App\Game\RoomManager;
use App\Livewire\Player\PlayerGame;
use App\Livewire\Player\PlayerLobby;
use App\Livewire\Player\PlayerResults;
use App\Models\PlayerAnswer;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

const RESULTS_TOKEN = 'r3sults000000000000000000000000000000000000000000000000000000000';

beforeEach(function () {
    Event::fake([GameStarted::class, GameFinished::class, RoomRestarted::class]);
    Queue::fake();
});

/** The phone of one player of a finished game, identified by its guest cookie. */
function resultsPhone(Room $room, string $nickname, array $me = []): Testable
{
    $room->players()->where('guest_token', RESULTS_TOKEN)->update(['guest_token' => null]);
    $room->players()->where('nickname', $nickname)->update(['guest_token' => RESULTS_TOKEN, 'locale' => 'en', ...$me]);

    return Livewire::withCookie('lamma_guest', RESULTS_TOKEN)->test(PlayerResults::class, ['room' => $room]);
}

/** Give a player correct answers on the first $count questions. */
function giveCorrect(Room $room, string $nickname, int $count): void
{
    $player = $room->players()->where('nickname', $nickname)->first();
    foreach ($room->roomQuestions()->take($count)->get() as $roomQuestion) {
        PlayerAnswer::create(['room_question_id' => $roomQuestion->id, 'room_player_id' => $player->id, 'question_option_id' => null, 'answered_at' => now(), 'is_correct' => true, 'points' => 100]);
    }
}

describe('the rank tile and the headline (player-6)', function () {
    it('shows 1st on sun, 2nd on teal, 3rd and below on coral', function () {
        $room = finishedGame(['Ali' => 800, 'Sara' => 700, 'Maya' => 500, 'Omar' => 100]);

        resultsPhone($room, 'Ali')->assertSeeHtml('data-test="rank-tile"')->assertSeeHtml('bg-sun flex size-[150px]')->assertSee('1')->assertSee('st');
        resultsPhone($room, 'Sara')->assertSeeHtml('bg-teal flex size-[150px]')->assertSee('2nd');
        resultsPhone($room, 'Maya')->assertSeeHtml('bg-coral flex size-[150px]')->assertSee('3rd');
        resultsPhone($room, 'Omar')->assertSeeHtml('bg-coral flex size-[150px]')->assertSee('4th');
    });

    it('says something fitting to each place', function () {
        $room = finishedGame(['Ali' => 800, 'Sara' => 700, 'Maya' => 500, 'Omar' => 100]);

        resultsPhone($room, 'Ali')->assertSeeHtml('You won, <bdi dir="ltr">Ali</bdi>!');
        resultsPhone($room, 'Sara')->assertSeeHtml('So close, <bdi dir="ltr">Sara</bdi>!');
        resultsPhone($room, 'Maya')->assertSeeHtml('Nice one, <bdi dir="ltr">Maya</bdi>!');
        resultsPhone($room, 'Omar')->assertSeeHtml('Good game, <bdi dir="ltr">Omar</bdi>!');
    });

    it('does not say you won when nobody scored', function () {
        $room = finishedGame(['Ali' => 0, 'Sara' => 0]);

        resultsPhone($room, 'Ali')->assertDontSee('You won')->assertSeeHtml('Good game, ');
    });

    it('shows the score and how many answers were right', function () {
        $room = finishedGame(['Ali' => 800, 'Sara' => 700], questions: 5);
        giveCorrect($room, 'Sara', 3);

        resultsPhone($room, 'Sara')->assertSeeHtml('<bdi dir="ltr">700</bdi> points · ')->assertSeeHtml('<bdi dir="ltr">3</bdi> of <bdi dir="ltr">5</bdi> correct');
    });

    it('marks a tie, and both tied players are first', function () {
        $room = finishedGame(['Ali' => 800, 'Sara' => 800, 'Maya' => 500]);

        resultsPhone($room, 'Sara')->assertSee('Tied for 1st')->assertSeeHtml('data-rank="1"');
        resultsPhone($room, 'Maya')->assertSee('3rd')->assertDontSee('Tied for');
    });

    it('names the place for screen readers', function () {
        $room = finishedGame(['Ali' => 800, 'Sara' => 700]);

        resultsPhone($room, 'Sara')->assertSeeHtml('aria-label="Your place: 2nd"');
    });
});

describe('the leaderboard', function () {
    it('lists everyone best first and outlines your own row', function () {
        $room = finishedGame(['Ali' => 800, 'Sara' => 700, 'Maya' => 500]);

        $page = resultsPhone($room, 'Sara');

        $page->assertSeeInOrder(['Ali', '800', 'Sara', '(you)', '700', 'Maya', '500']);
        expect($page->html())->toMatch('/<li[^>]*border-3 border-navy[^>]*data-you/s')->and(substr_count($page->html(), 'data-you'))->toBe(1);
    });

    it('keeps nicknames and scores left to right and escapes the nicknames', function () {
        $room = finishedGame(['<i>Ali</i>' => 800, 'Sara' => 700]);

        $page = resultsPhone($room, 'Sara');

        $page->assertDontSeeHtml('<i>Ali</i>')->assertSeeHtml('<bdi dir="ltr">&lt;i&gt;Ali&lt;/i&gt;</bdi>')->assertSeeHtml('dir="ltr">800</span>');
    });

    it('speaks Arabic to an Arabic player, with plain numbers', function () {
        $room = finishedGame(['Ali' => 800, 'Sara' => 700]);

        $page = resultsPhone($room, 'Sara', ['locale' => 'ar']);

        $page->assertSee('اقتربت كثيرًا')->assertSee('(أنت)')->assertSee('احفظ نتيجتك')->assertSee('مغادرة الغرفة');
        expect($page->html())->not->toContain('2nd');
    });
});

describe('save your score', function () {
    it('offers a guest to log in or sign up, going through a link that remembers this page', function () {
        $room = finishedGame(['Ali' => 800, 'Sara' => 700]);

        resultsPhone($room, 'Sara')->assertSee('Save your score')->assertSee('Log in or sign up to save this game to your profile.')
            ->assertSeeHtml('href="'.route('play.save', [$room->code, 'login']).'"')->assertSeeHtml('href="'.route('play.save', [$room->code, 'register']).'"')
            ->assertSeeHtml('data-state="guest"')->assertDontSee('Saved to your profile');
    });

    it('tells a logged-in player it is saved, with a link to their games', function () {
        $room = finishedGame(['Ali' => 800]);
        $user = User::factory()->create();
        $mine = RoomPlayer::factory()->for($room)->forUser($user)->create(['nickname' => 'Sara', 'score' => 700, 'locale' => 'en', 'left_at' => null]);

        Livewire::actingAs($user)->test(PlayerResults::class, ['room' => $room])->assertSee('Saved to your profile')->assertSeeHtml('href="'.route('me.games').'"')
            ->assertSeeHtml('data-state="saved"')->assertDontSee('Save your score');
    });

    it('says a game cannot be added when a logged-in player is still on a guest row', function () {
        $room = finishedGame(['Ali' => 800, 'Sara' => 700]);
        $room->players()->where('nickname', 'Sara')->update(['guest_token' => RESULTS_TOKEN, 'locale' => 'en']);
        $user = User::factory()->create();

        Livewire::actingAs($user)->withCookie('lamma_guest', RESULTS_TOKEN)->test(PlayerResults::class, ['room' => $room])
            ->assertSeeHtml('data-state="unsaved"')->assertSee("can't be added to your profile")->assertDontSee('Save your score');
    });

    it('sends the guest through the login screen and back to the results, saved', function () {
        $room = finishedGame(['Ali' => 800, 'Sara' => 700]);
        $room->players()->where('nickname', 'Sara')->update(['guest_token' => RESULTS_TOKEN, 'locale' => 'en']);
        $user = User::factory()->create();

        $this->withCookie('lamma_guest', RESULTS_TOKEN)->get(route('play.save', [$room->code, 'login']))->assertRedirect(route('login'));
        expect(session('url.intended'))->toBe(route('play', $room->code));

        $this->withCookie('lamma_guest', RESULTS_TOKEN)->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('play', $room->code));

        expect($room->players()->where('nickname', 'Sara')->value('user_id'))->toBe($user->id);
        $this->withCookie('lamma_guest', RESULTS_TOKEN)->get(route('play', $room->code))->assertOk()->assertSee('Saved to your profile')->assertDontSee('Save your score');
    });

    it('does the same through sign up', function () {
        $room = finishedGame(['Ali' => 800, 'Sara' => 700]);
        $room->players()->where('nickname', 'Sara')->update(['guest_token' => RESULTS_TOKEN, 'locale' => 'en']);

        $this->withCookie('lamma_guest', RESULTS_TOKEN)->get(route('play.save', [$room->code, 'register']))->assertRedirect(route('register'));

        $this->withCookie('lamma_guest', RESULTS_TOKEN)->post(route('register.store'), [
            'name' => 'Sara', 'email' => 'sara@example.com', 'password' => 'a-Long-Passw0rd!', 'password_confirmation' => 'a-Long-Passw0rd!',
        ])->assertRedirect(route('play', $room->code));

        $user = User::where('email', 'sara@example.com')->first();
        expect($room->players()->where('nickname', 'Sara')->value('user_id'))->toBe($user->id);
        $this->withCookie('lamma_guest', RESULTS_TOKEN)->get(route('play', $room->code))->assertSee('Saved to your profile');
    });

    it('does not claim a game played longer ago than the claim window', function () {
        $room = finishedGame(['Ali' => 800, 'Sara' => 700]);
        $room->players()->where('nickname', 'Sara')->update(['guest_token' => RESULTS_TOKEN, 'locale' => 'en', 'created_at' => now()->subHours(config('lamma.guest_claim_hours') + 1)]);
        $user = User::factory()->create();

        $this->withCookie('lamma_guest', RESULTS_TOKEN)->get(route('play.save', [$room->code, 'login']));
        $this->withCookie('lamma_guest', RESULTS_TOKEN)->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        expect($room->players()->where('nickname', 'Sara')->value('user_id'))->toBeNull();
        $this->withCookie('lamma_guest', RESULTS_TOKEN)->get(route('play', $room->code))->assertSee("can't be added to your profile");
    });

    it('only works for someone in the room, and only for login and register', function () {
        $room = finishedGame(['Ali' => 800]);

        $this->get(route('play.save', [$room->code, 'login']))->assertRedirect(route('join', ['code' => $room->code]));
        $this->get('/play/'.$room->code.'/save/logout')->assertNotFound();
    });
});

describe('Leave room and staying', function () {
    it('says to stay, and Leave room is a link home that does not touch the player\'s row', function () {
        $room = finishedGame(['Ali' => 800, 'Sara' => 700]);

        $page = resultsPhone($room, 'Sara');

        $page->assertSee("Stay here. If the host starts another round, you're in.")->assertSeeHtml('data-test="leave-button"')->assertSeeHtml('href="'.route('home').'"');
        expect($page->html())->not->toContain('wire:click="leave"')->and($room->players()->where('nickname', 'Sara')->exists())->toBeTrue();
    });
});

describe('Play again: every phone moves to the new room', function () {
    it('follows the broadcast to the new lobby', function () {
        $room = finishedGame(['Ali' => 800, 'Sara' => 700]);
        $page = resultsPhone($room, 'Sara');

        $new = app(RoomManager::class)->playAgain($room, $room->host);

        $page->dispatch("echo-presence:room.{$room->code},RoomRestarted")->assertRedirect(route('play', $new->code));
    });

    it('moves guests and logged-in players alike, and each can open the new lobby', function () {
        $room = finishedGame(['Ali' => 800, 'Sara' => 700]);
        $room->players()->where('nickname', 'Ali')->update(['guest_token' => 'a3sults000000000000000000000000000000000000000000000000000000000']);
        $room->players()->where('nickname', 'Sara')->update(['guest_token' => 'b3sults000000000000000000000000000000000000000000000000000000000', 'locale' => 'ar']);
        $user = User::factory()->create();
        RoomPlayer::factory()->for($room)->forUser($user)->create(['nickname' => 'Account', 'score' => 300, 'left_at' => null]);

        $new = app(RoomManager::class)->playAgain($room, $room->host);

        foreach (['a3sults000000000000000000000000000000000000000000000000000000000', 'b3sults000000000000000000000000000000000000000000000000000000000'] as $token) {
            Livewire::withCookie('lamma_guest', $token)->test(PlayerResults::class, ['room' => $room])->call('follow')->assertRedirect(route('play', $new->code));
            $this->withCookie('lamma_guest', $token)->get(route('play', $new->code))->assertOk();
        }
        Livewire::actingAs($user)->test(PlayerResults::class, ['room' => $room])->call('follow')->assertRedirect(route('play', $new->code));
        $this->actingAs($user)->get(route('play', $new->code))->assertOk();
    });

    it('still finds the new lobby when the broadcast was missed: the poll asks the database', function () {
        $room = finishedGame(['Ali' => 800]);
        $page = resultsPhone($room, 'Ali');
        $page->assertSeeHtml('wire:poll.5s="follow"')->call('follow')->assertNoRedirect();

        $new = app(RoomManager::class)->playAgain($room, $room->host);

        $page->call('follow')->assertRedirect(route('play', $new->code));
    });

    it('does not follow into a room that was closed, or one the player was not copied into', function () {
        $room = finishedGame(['Ali' => 800, 'Sara' => 700]);
        $room->players()->where('nickname', 'Sara')->update(['left_at' => now()->subMinute()]);
        $sara = resultsPhone($room, 'Sara');
        $new = app(RoomManager::class)->playAgain($room, $room->host);

        $sara->call('follow')->assertNoRedirect(); // Sara had left: she was not copied

        $room->players()->where('nickname', 'Ali')->update(['guest_token' => 'x3sults000000000000000000000000000000000000000000000000000000000']);
        app(RoomManager::class)->close($new);
        Livewire::withCookie('lamma_guest', 'x3sults000000000000000000000000000000000000000000000000000000000')->test(PlayerResults::class, ['room' => $room])->call('follow')->assertNoRedirect();
    });

    it('listens for RoomRestarted on the old room\'s channel', function () {
        $room = finishedGame(['Ali' => 800]);

        expect(resultsPhone($room, 'Ali')->instance()->getListeners())->toHaveKey("echo-presence:room.{$room->code},RoomRestarted", 'follow');
    });
});

describe('inside the phone\'s page', function () {
    it('shows the results when the game ends, whether the phone was watching or reloads', function () {
        $room = finishedGame(['Ali' => 800, 'Sara' => 700]);
        $room->players()->where('nickname', 'Sara')->update(['guest_token' => RESULTS_TOKEN, 'locale' => 'en']);

        Livewire::withCookie('lamma_guest', RESULTS_TOKEN)->test(PlayerGame::class, ['room' => $room])->assertSeeHtml('data-test="player-results"')->assertSeeHtml('data-phase="over"');
        Livewire::withCookie('lamma_guest', RESULTS_TOKEN)->test(PlayerLobby::class, ['room' => $room])->assertSeeHtml('data-test="player-results"')->assertDontSeeHtml('data-test="ready-button"');
        $this->withCookie('lamma_guest', RESULTS_TOKEN)->get(route('play', $room->code))->assertOk()->assertSee('So close')->assertSee('Leave room');
    });

    it('shows the plain closed card for a game closed part-way', function () {
        $room = finishedGame(['Ali' => 800]);
        $room->roomQuestions()->where('position', 3)->update(['revealed_at' => null]);
        $room->players()->update(['guest_token' => RESULTS_TOKEN, 'locale' => 'en']);

        Livewire::withCookie('lamma_guest', RESULTS_TOKEN)->test(PlayerLobby::class, ['room' => $room])->assertSee('This room has been closed.')->assertDontSeeHtml('data-test="player-results"');
    });

    it('is for participants only', function () {
        $room = finishedGame(['Ali' => 800]);

        Livewire::test(PlayerResults::class, ['room' => $room])->assertForbidden();
    });
});
