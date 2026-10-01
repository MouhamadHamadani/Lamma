<?php

use App\Events\GameFinished;
use App\Events\GameStarted;
use App\Game\PlayerStats;
use App\Livewire\Player\MyGames;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    Event::fake([GameStarted::class, GameFinished::class]);
    Queue::fake();
});

function myGames(User $user): Testable
{
    return Livewire::actingAs($user)->test(MyGames::class);
}

describe('who can see it', function () {
    it('is for logged-in users: a guest is sent to log in', function () {
        $this->get(route('me.games'))->assertRedirect(route('login'));
    });

    it('is at /me/games', function () {
        expect(route('me.games', absolute: false))->toBe('/me/games');
        $this->actingAs(User::factory()->create())->get('/me/games')->assertOk()->assertSee('My games');
    });

    it('is linked from the user menu and the sidebar', function () {
        $page = $this->actingAs(User::factory()->create())->get(route('dashboard'));

        expect(substr_count($page->getContent(), 'href="'.route('me.games').'"'))->toBeGreaterThanOrEqual(2);
    });

    it('is linked from the landing page for a logged-in user, not for a guest', function () {
        $this->actingAs(User::factory()->create())->get('/')->assertSee('href="'.route('me.games').'"', false);
        auth()->logout();
        $this->get('/')->assertDontSee('href="'.route('me.games').'"', false);
    });
});

describe('only saved games', function () {
    it('lists the games tied to the account', function () {
        $user = User::factory()->create();
        savedGame($user, 700, ['Rival' => 900]);

        myGames($user)->assertSeeHtml('data-test="game"')->assertSee('700 pts')->assertDontSee('No saved games yet');
    });

    it('leaves out a guest row from the same device that was never claimed', function () {
        $user = User::factory()->create();
        $guestRoom = finishedGame(['Sara' => 700, 'Ali' => 800]);
        $guestRoom->players()->where('nickname', 'Sara')->update(['guest_token' => str_repeat('g', 64)]);
        savedGame($user, 300);

        $page = myGames($user);

        expect(substr_count($page->html(), 'data-test="game"'))->toBe(1);
        $page->assertSee('300 pts')->assertDontSee('700 pts');
    });

    it('leaves out other people\'s games', function () {
        $user = User::factory()->create();
        savedGame(User::factory()->create(), 700);

        myGames($user)->assertSeeHtml('data-test="empty"')->assertSee('No saved games yet')->assertDontSeeHtml('data-test="game"');
    });

    it('leaves out games that did not run to the end', function () {
        $user = User::factory()->create();
        [$partway] = savedGame($user, 400);
        $partway->roomQuestions()->where('position', 3)->update(['revealed_at' => null]);
        $lobby = Room::factory()->create();
        RoomPlayer::factory()->for($lobby)->forUser($user)->create(['score' => 0]);
        [$playing] = savedGame($user, 500);
        $playing->update(['status' => 'playing']);

        myGames($user)->assertDontSeeHtml('data-test="game"')->assertSee('No saved games yet');
    });

    it('shows nothing of a game once it is claimed by someone else', function () {
        $other = User::factory()->create();
        $user = User::factory()->create();
        [$room, $row] = savedGame($other, 700);

        myGames($user)->assertDontSee('700 pts');
        expect($row->fresh()->user_id)->toBe($other->id);
    });

    it('shows a claimed guest game once it has been attached to the account', function () {
        $user = User::factory()->create();
        $room = finishedGame(['Sara' => 700, 'Ali' => 800]);
        $row = $room->players()->where('nickname', 'Sara')->first();
        myGames($user)->assertDontSee('700 pts');

        $row->update(['user_id' => $user->id]);

        myGames($user)->assertSee('700 pts');
    });
});

describe('each game', function () {
    it('shows the date, categories, rank, score and number of players', function () {
        $user = User::factory()->create();
        $science = categoryWithQuestions(6, category: ['name' => ['en' => 'Science', 'ar' => 'العلوم']]);
        $geography = categoryWithQuestions(6, category: ['name' => ['en' => 'Geography', 'ar' => 'الجغرافيا']]);
        [$room] = savedGame($user, 700, ['Ali' => 800, 'Maya' => 500], settings: ['categoryIds' => [$science->id, $geography->id]]);
        $room->update(['finished_at' => '2026-09-20 18:30:00']);

        $page = myGames($user);

        $page->assertSee('20 September 2026')->assertSee('Science · Geography')->assertSee('700 pts')->assertSee('Players: 3')->assertSeeHtml('data-rank="2"')->assertSeeHtml('aria-label="Your place: 2nd"');
    });

    it('shows how many of the questions were answered correctly', function () {
        $user = User::factory()->create();
        savedGame($user, 200, correct: 2, questions: 5);

        myGames($user)->assertSeeHtml('<bdi dir="ltr">2 of 5</bdi>');
    });

    it('ranks ties together', function () {
        $user = User::factory()->create();
        savedGame($user, 800, ['Ali' => 800, 'Maya' => 500]);

        myGames($user)->assertSeeHtml('data-rank="1"');
    });

    it('lists the newest game first', function () {
        $user = User::factory()->create();
        [$old] = savedGame($user, 111);
        [$new] = savedGame($user, 222);
        $old->update(['finished_at' => now()->subDays(5)]);
        $new->update(['finished_at' => now()->subDay()]);

        myGames($user)->assertSeeInOrder(['222 pts', '111 pts']);
    });

    it('pages through a long history', function () {
        $user = User::factory()->create();
        foreach (range(1, 16) as $n) {
            [$room] = savedGame($user, $n * 10);
            $room->update(['finished_at' => now()->subHours(20 - $n)]);
        }

        $page = myGames($user);

        expect(substr_count($page->html(), 'data-test="game"'))->toBe(15);
        $page->assertSeeHtml('data-test="score">160 pts<')->assertDontSeeHtml('data-test="score">10 pts<')->assertSeeHtml('data-test="next-page"');
        $page->call('nextPage')->assertSeeHtml('data-test="score">10 pts<')->assertDontSeeHtml('data-test="score">160 pts<');
    });
});

describe('totals', function () {
    it('counts games played, wins and the share of questions answered correctly', function () {
        $user = User::factory()->create();
        savedGame($user, 900, ['Ali' => 500], correct: 3, questions: 3);   // win, 3 of 3
        savedGame($user, 300, ['Ali' => 800], correct: 1, questions: 3);   // second, 1 of 3
        savedGame($user, 800, ['Ali' => 800], correct: 2, questions: 4);   // tied for first counts as a win, 2 of 4

        $totals = app(PlayerStats::class)->totals($user);

        expect($totals)->toBe(['played' => 3, 'wins' => 2, 'correct' => 6, 'questions' => 10, 'rate' => 60]);
    });

    it('does not count a win with no points', function () {
        $user = User::factory()->create();
        savedGame($user, 0, ['Ali' => 0]);

        expect(app(PlayerStats::class)->totals($user)['wins'])->toBe(0);
    });

    it('shows them at the top', function () {
        $user = User::factory()->create();
        savedGame($user, 900, ['Ali' => 500], correct: 3, questions: 3);
        savedGame($user, 300, ['Ali' => 800], correct: 1, questions: 3);

        $page = myGames($user);

        $page->assertSeeHtml('data-test="total-played">2<')->assertSeeHtml('data-test="total-wins">1<')->assertSeeHtml('data-test="total-rate">67%<')->assertSeeHtml('<bdi dir="ltr">4 of 6</bdi>');
    });

    it('is zero, with a dash for the rate, before any game', function () {
        $page = myGames(User::factory()->create());

        $page->assertSeeHtml('data-test="total-played">0<')->assertSeeHtml('data-test="total-wins">0<')->assertSeeHtml('data-test="total-rate">—<');
    });

    it('ignores games that are not saved or not finished', function () {
        $user = User::factory()->create();
        $guest = finishedGame(['Sara' => 700]);
        $guest->players()->first()->update(['guest_token' => str_repeat('g', 64)]);
        [$partway] = savedGame($user, 400);
        $partway->roomQuestions()->update(['revealed_at' => null]);

        expect(app(PlayerStats::class)->totals($user)['played'])->toBe(0);
    });
});

describe('both languages', function () {
    it('speaks Arabic with Arabic month names and Western digits', function () {
        $user = User::factory()->create(['preferred_locale' => 'ar']);
        [$room] = savedGame($user, 700);
        $room->update(['finished_at' => '2026-09-20 18:30:00']);
        app()->setLocale('ar');

        $page = myGames($user);

        $page->assertSee('ألعابي')->assertSee('الألعاب التي لعبتها')->assertSee('الانتصارات')->assertSee('الإجابات الصحيحة')->assertSee('سبتمبر')->assertSee('700 نقطة');
        expect($page->html())->toContain('2026')->and($page->html())->not->toMatch('/[٠-٩]/u');
    });

    it('keeps scores and totals left to right', function () {
        $user = User::factory()->create();
        savedGame($user, 700);

        myGames($user)->assertSeeHtml('dir="ltr" data-test="total-played"')->assertSeeHtml('dir="ltr" data-test="score"');
    });
});
