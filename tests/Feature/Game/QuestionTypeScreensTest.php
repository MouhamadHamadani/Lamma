<?php

use App\Enums\HostScreenLocale;
use App\Events\GameFinished;
use App\Events\GameStarted;
use App\Events\PlayerAnswered;
use App\Events\QuestionRevealed;
use App\Events\QuestionStarted;
use App\Events\ScoreboardUpdated;
use App\Game\GameEngine;
use App\Livewire\Host\HostGame;
use App\Livewire\Player\PlayerGame;
use App\Models\PlayerAnswer;
use App\Models\Question;
use App\Models\Room;
use App\Models\RoomPlayer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    Event::fake([GameStarted::class, QuestionStarted::class, PlayerAnswered::class, QuestionRevealed::class, ScoreboardUpdated::class, GameFinished::class]);
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00.000'));
});

function typedHost(Room $room): Testable
{
    return Livewire::actingAs($room->host)->test(HostGame::class, ['room' => $room]);
}

/** The first player's phone, in this language. */
function typedPhone(Room $room, string $locale = 'en'): Testable
{
    $room->players()->orderBy('id')->first()->update(['guest_token' => typedToken(), 'locale' => $locale]);

    return Livewire::withCookie('lamma_guest', typedToken())->test(PlayerGame::class, ['room' => $room]);
}

/** A game whose question 1 is of this type; $mode is the host screen's language. */
function typedGame(string $type, HostScreenLocale $mode = HostScreenLocale::Both, bool $withImage = false, float $number = 1969): array
{
    $room = typedRoom([$type], players: 3, settings: ['hostScreenLocale' => $mode, 'doubleFinal' => false], withImage: $withImage, number: $number);
    $question = startGame($room);

    return [$room, $question, $room->players()->orderBy('id')->get()];
}

function typedReveal(Room $room): void
{
    app(GameEngine::class)->reveal($room, 1, force: true);
}

describe('the host screen: true or false', function () {
    it('shows two big tiles, True then False, the A and B tiles, with the Arabic words beside them in Both', function () {
        [$room, $question] = typedGame('true_false');
        [$true, $false] = $question->question->options;

        $page = typedHost($room)->assertSee('True or false?')->assertSeeHtml('data-test="question-kind"')
            ->assertSeeHtml('data-option="'.$true->id.'"')->assertSeeHtml('data-option="'.$false->id.'"')
            ->assertSeeInOrder(['True', 'صحيح', 'False', 'خطأ']);

        expect(substr_count($page->html(), 'data-option='))->toBe(2)
            ->and($page->html())->toContain('h-[clamp(130px,26dvh,260px)]')
            ->and(substr_count($page->html(), 'viewBox="0 0 18 18"'))->toBeGreaterThanOrEqual(2);   // a shape on each tile (colour is never alone)
    });

    it('keeps True as A (coral, triangle) and False as B (teal, circle), whatever the stored order of other questions', function () {
        [$room, $question] = typedGame('true_false', HostScreenLocale::En);

        $page = typedHost($room);

        expect($question->option_order)->toBe($question->question->options->pluck('id')->all());
        $page->assertSeeInOrder(['bg-coral', 'True', 'bg-teal', 'False'], false);
    });

    it('says it in Arabic on an Arabic screen', function () {
        [$room] = typedGame('true_false', HostScreenLocale::Ar);

        typedHost($room)->assertSee('صح أم خطأ؟')->assertSee('صحيح')->assertSee('خطأ')->assertDontSee('True or false?')->assertDontSee('True');
    });

    it('marks the right tile on the reveal and fades the other', function () {
        [$room, $question] = typedGame('true_false');   // True is correct
        [$true, $false] = $question->question->options;
        typedReveal($room);

        $page = typedHost($room->fresh());

        $page->assertSeeHtml('data-option="'.$true->id.'" data-correct="true"')->assertSeeHtml('data-option="'.$false->id.'" data-correct="false"')
            ->assertSee('Correct');
    });
});

describe('the host screen: a picture', function () {
    it('shows the picture above the options with the same alt text for every question, and shrinks the tiles', function () {
        [$room, $question] = typedGame('multiple_choice', withImage: true);

        $page = typedHost($room);
        $html = $page->html();

        expect($html)->toContain('data-test="question-image"')->toContain('picture-0.webp')->toContain('alt="Picture for this question"')
            ->toContain('h-[clamp(72px,11.5dvh,112px)]')                  // compact tiles
            ->and($html)->not->toContain('h-[clamp(96px,16.67dvh,150px)]');
        // The alt text is generic: never the question, never an answer.
        preg_match('/data-test="question-image".*?<img[^>]*alt="([^"]*)"/s', $html, $alt);
        expect($alt[1])->toBe('Picture for this question')
            ->and($alt[1])->not->toContain($question->question->getTranslation('text', 'en'));
    });

    it('has the alt text in Arabic on an Arabic screen', function () {
        [$room] = typedGame('multiple_choice', HostScreenLocale::Ar, withImage: true);

        typedHost($room)->assertSeeHtml('alt="صورة لهذا السؤال"');
    });

    it('keeps a smaller picture beside the title on the reveal', function () {
        [$room] = typedGame('true_false', withImage: true);
        typedReveal($room);

        $html = typedHost($room->fresh())->html();

        expect($html)->toContain('data-test="question-image"')->toContain('h-[clamp(64px,10dvh,100px)]');
    });

    it('has no picture frame at all on a question without one', function () {
        [$room] = typedGame('multiple_choice');

        typedHost($room)->assertDontSeeHtml('data-test="question-image"');
    });
});

describe('the host screen: closest number', function () {
    it('has no tiles, only who has guessed, with the closest-wins hint in both languages', function () {
        [$room, , $players] = typedGame('closest_number');
        guess($players[0], 1960);
        guess($players[1], 1980);

        $page = typedHost($room);

        $page->assertSeeHtml('data-test="number-wait"')->assertDontSeeHtml('data-option=')->assertDontSeeHtml('data-test="answers"')
            ->assertSee('Type your guess on your phone')->assertSee('اكتب تخمينك على هاتفك')->assertSee('The closest guess wins a bonus.')
            ->assertSeeText('2 of 3 guessed')->assertSeeHtml('data-test="answered-avatars"');
        expect($page->html())->not->toContain('1960')->not->toContain('1980');   // nobody's number on the big screen
    });

    it('counts the guessers as they guess, without saying what they guessed', function () {
        [$room, , $players] = typedGame('closest_number');

        typedHost($room)->assertSeeText('0 of 3 guessed');
        guess($players[2], 1);
        typedHost($room)->assertSeeText('1 of 3 guessed');
    });

    it('shows the number line, the right number, the ranked guesses nearest first and who was closest, on the reveal', function () {
        [$room, , $players] = typedGame('closest_number');
        guess($players[0], 1966);
        guess($players[1], 1969);
        guess($players[2], 1900);
        typedReveal($room);

        $page = typedHost($room->fresh());
        $html = $page->html();

        $page->assertSeeHtml('data-test="number-line"')->assertSeeHtml('data-test="correct-marker"')->assertSeeHtml('data-test="guess-list"')
            ->assertSee('Exact')->assertSee('Off by')->assertSee('Closest')->assertSeeText('2 of 3 were close enough')
            ->assertDontSeeHtml('data-test="answers"');
        // (this question has a fun fact, so the list keeps two rows and says how many more; every guess is still a pin on the line)
        expect(substr_count($html, 'data-test="guess-row"'))->toBe(2)->and(substr_count($html, 'data-test="pin"'))->toBe(3)
            ->and($html)->toContain('data-closest="true"');
        $page->assertSee('and')->assertSeeText('and 1 more');

        $names = collect([$players[1], $players[0]])->map(fn (RoomPlayer $player) => $player->nickname)->all();
        $page->assertSeeInOrder([...$names, '+']);   // nearest first: the exact guess, then the one 3 away
    });

    it('draws a line even if everybody guessed the same, and when nobody guessed', function () {
        [$room, , $players] = typedGame('closest_number');
        guess($players[0], 1969);
        guess($players[1], 1969);
        typedReveal($room);

        typedHost($room->fresh())->assertSeeHtml('data-test="number-line"')->assertSeeHtml('data-test="correct-marker"');

        [$other] = typedGame('closest_number');
        typedReveal($other);
        typedHost($other->fresh())->assertSeeHtml('data-test="number-line"')->assertSee('Nobody guessed this one.');
    });

    it('does not mix Arabic digits in: the numbers on screen are Western in Arabic too', function () {
        [$room, , $players] = typedGame('closest_number', HostScreenLocale::Ar);
        guess($players[0], '١٩٦٦');
        typedReveal($room);

        $html = typedHost($room->fresh())->html();

        expect($html)->toContain('1966')->toContain('1969')->and(preg_match('/[٠-٩]/u', $html))->toBe(0);
    });
});

describe('the fun fact', function () {
    it('shows under the answer on the reveal, never before, in both languages in Both and in one otherwise', function (string $type) {
        [$room] = typedGame($type);

        typedHost($room)->assertDontSee('A fun fact.')->assertDontSee('معلومة طريفة.')->assertDontSeeHtml('data-test="fun-fact"');

        typedReveal($room);
        typedHost($room->fresh())->assertSeeHtml('data-test="fun-fact"')->assertSee('A fun fact.')->assertSee('معلومة طريفة.');
    })->with(['multiple_choice', 'true_false', 'closest_number']);

    it('is in the screen language alone when the screen is not Both', function () {
        [$room] = typedGame('true_false', HostScreenLocale::Ar);
        typedReveal($room);
        typedHost($room->fresh())->assertSee('معلومة طريفة.')->assertDontSee('A fun fact.');

        [$room] = typedGame('true_false', HostScreenLocale::En);
        typedReveal($room);
        typedHost($room->fresh())->assertSee('A fun fact.')->assertDontSee('معلومة طريفة.');
    });

    it('is optional: a question without one shows nothing, and falls back to the other language when only one was written', function () {
        [$room, $question] = typedGame('true_false', HostScreenLocale::En);
        Question::query()->whereKey($question->question_id)->toBase()->update(['explanation' => null]);
        typedReveal($room);
        typedHost($room->fresh())->assertDontSeeHtml('data-test="fun-fact"');

        [$room, $question] = typedGame('true_false', HostScreenLocale::En);
        Question::query()->whereKey($question->question_id)->toBase()->update(['explanation' => json_encode(['ar' => 'عربي فقط.'], JSON_UNESCAPED_UNICODE)]);
        typedReveal($room);
        typedHost($room->fresh())->assertSee('عربي فقط.')->assertSeeHtml('lang="ar"');
    });

    it('is read out with the answer', function () {
        [$room] = typedGame('multiple_choice', HostScreenLocale::En);
        typedReveal($room);

        typedHost($room->fresh())->assertSeeHtml('data-test="announcer"')->assertSeeText('A fun fact.');
    });
});

describe('the phone: true or false', function () {
    it('has two tall tiles, True then False, in the player\'s language', function (string $locale, string $true, string $false, string $kind) {
        [$room, $question] = typedGame('true_false');
        $page = typedPhone($room, $locale);

        $page->assertSee($kind)->assertSeeInOrder([$true, $false])
            ->assertSeeHtml('wire:click="answer('.$question->question->options[0]->id.')"')->assertSeeHtml('wire:click="answer('.$question->question->options[1]->id.')"');
        expect($page->html())->toContain('h-32 w-full')->and(substr_count($page->html(), 'wire:click="answer('))->toBe(2);
    })->with([
        'English' => ['en', 'True', 'False', 'True or false?'],
        'Arabic' => ['ar', 'صحيح', 'خطأ', 'صح أم خطأ؟'],
    ]);

    it('locks the tap, like any answer, and reveals right and wrong', function () {
        [$room, $question] = typedGame('true_false');
        $true = $question->question->options[0];
        $page = typedPhone($room);

        $page->call('answer', $true->id)->assertSeeHtml('data-phase="answered"')->assertSee('Answer locked in');
        typedReveal($room);

        typedPhone($room->fresh())->assertSeeHtml('data-phase="correct"')->assertSee('Correct!')->assertSeeHtml('lammaCountUp(200,');
    });
});

describe('the phone: a picture', function () {
    it('shows it in the question card above the tiles, which keep their full height', function () {
        [$room] = typedGame('multiple_choice', withImage: true);

        $html = typedPhone($room)->html();

        expect($html)->toContain('data-test="question-image"')->toContain('alt="Picture for this question"')->toContain('h-[clamp(88px,19dvh,200px)]')
            ->and(substr_count($html, 'h-22 w-full'))->toBe(4)                 // four tiles, 88px (72px on a short screen)
            ->and(strpos($html, 'data-test="question-image"'))->toBeLessThan(strpos($html, 'data-test="answers"'));
    });

    it('says the alt text in the player\'s language', function () {
        [$room] = typedGame('multiple_choice', withImage: true);

        typedPhone($room, 'ar')->assertSeeHtml('alt="صورة لهذا السؤال"');
    });
});

describe('the phone: closest number', function () {
    it('has a number box and a button instead of tiles, in English and Arabic', function (string $locale, string $hint, string $button) {
        [$room] = typedGame('closest_number');

        $page = typedPhone($room, $locale);
        $html = $page->html();

        $page->assertSee($hint)->assertSee($button)->assertDontSeeHtml('wire:click="answer(');
        expect($html)->toContain('inputmode="decimal"')->toContain('dir="ltr"')->toContain('data-test="number-input"')->toContain('type="text"')
            ->toContain('data-test="number-lock"')->toContain('wire:ignore')
            ->and(preg_match('/<input[^>]*dir="ltr"[^>]*inputmode="decimal"|<input[^>]*inputmode="decimal"[^>]*dir="ltr"/', $html))->toBe(1);
    })->with([
        'English' => ['en', 'The closest guess wins a bonus.', 'Lock in my guess'],
        'Arabic' => ['ar', 'أقرب تخمين يربح مكافأة.', 'ثبّت تخميني'],
    ]);

    it('locks the guess with one go and shows it back as the server read it', function () {
        [$room, , $players] = typedGame('closest_number');
        $page = typedPhone($room);

        $page->call('guess', '١٩٦٦')->assertSeeHtml('data-phase="answered"')->assertSee('Guess locked in')->assertSeeHtml('data-test="locked-number"')->assertSeeText('1966')
            ->assertSee('1 of 3 guessed');
        expect(PlayerAnswer::first()->number_value)->toBe(1966.0)->and($players[0]->answers()->count())->toBe(1);
    });

    it('refuses what is not a number with a message, locks nothing, and lets the player try again', function () {
        [$room] = typedGame('closest_number');
        $page = typedPhone($room);

        $page->call('guess', 'about 1969')->assertSee('That is not a number.')->assertSeeHtml('role="alert"')->assertSeeHtml('data-phase="question"');
        expect(PlayerAnswer::count())->toBe(0);

        $page->call('guess', '1969')->assertDontSee('That is not a number.')->assertSeeHtml('data-phase="answered"');
        expect(PlayerAnswer::count())->toBe(1);
    });

    it('says the refusal in Arabic', function () {
        [$room] = typedGame('closest_number');

        typedPhone($room, 'ar')->call('guess', 'abc')->assertSee('هذا ليس رقمًا');
    });

    it('takes one guess: a second go does nothing', function () {
        [$room] = typedGame('closest_number');
        $page = typedPhone($room);

        $page->call('guess', '1966')->call('guess', '1969');

        expect(PlayerAnswer::count())->toBe(1)->and(PlayerAnswer::first()->number_value)->toBe(1966.0);
    });

    it('announces the question, the lock and the result to a screen reader', function () {
        [$room] = typedGame('closest_number');
        $page = typedPhone($room);

        $page->assertSeeHtml('data-test="announcer"')->assertSee('Question 1 of 1. Type your guess below.');
        $page->call('guess', '1969')->assertSee('Guess locked in');
        typedReveal($room);

        typedPhone($room->fresh())->assertSee('Correct, plus 150 points. The answer was 1969');
    });

    it('does not show the number anywhere before the reveal', function () {
        [$room, , $players] = typedGame('closest_number', number: 7351);
        $page = typedPhone($room);
        guess($players[1], 7351);

        expect($page->html())->not->toContain('7351');
        $page->call('guess', '7300');
        expect($page->html())->not->toContain('7351');
    });
});

describe('the phone: the reveal of a number', function () {
    it('is "Spot on!" for the exact answer, with the extra for the closest guess and both numbers', function () {
        [$room, , $players] = typedGame('closest_number');
        $page = typedPhone($room);
        $page->call('guess', '1969');
        guess($players[1], 1962);
        typedReveal($room);

        typedPhone($room->fresh())->assertSeeHtml('data-phase="correct"')->assertSee('Spot on!')->assertSeeHtml('lammaCountUp(150,')->assertSeeText('100 + 50 closest')
            ->assertSeeHtml('data-test="correct-number"')->assertSeeHtml('data-test="my-guess"')->assertSeeText('Correct answer')->assertSeeText('Your guess');
    });

    it('is "Close!" for a guess inside the tolerance, with no extra when somebody was closer', function () {
        [$room, , $players] = typedGame('closest_number');
        $page = typedPhone($room);
        $page->call('guess', '1964');   // 5 away: 50
        guess($players[1], 1969);
        typedReveal($room);

        $page = typedPhone($room->fresh());

        $page->assertSee('Close!')->assertSeeHtml('lammaCountUp(50,')->assertDontSee('closest')->assertSeeHtml('data-phase="correct"');
    });

    it('is "Not quite!" outside the tolerance, "Time\'s up!" without a guess, and shows the number either way', function () {
        [$room] = typedGame('closest_number');
        typedPhone($room)->call('guess', '1000');
        typedReveal($room);
        typedPhone($room->fresh())->assertSeeHtml('data-phase="wrong"')->assertSee('Not quite!')->assertSeeHtml('lammaCountUp(0,')->assertSeeHtml('data-test="correct-number"')->assertSeeText('1000');

        [$room] = typedGame('closest_number');
        typedReveal($room);
        typedPhone($room->fresh())->assertSeeHtml('data-phase="timeout"')->assertSee("Time's up!")->assertSeeHtml('data-test="correct-number"')->assertDontSeeHtml('data-test="my-guess"');
    });

    it('shows the x2 line on the last question', function () {
        $room = typedRoom(['closest_number'], settings: ['doubleFinal' => true]);
        startGame($room);
        typedPhone($room)->call('guess', '1969');
        typedReveal($room);

        typedPhone($room->fresh())->assertSeeHtml('lammaCountUp(300,')->assertSeeHtml('data-test="double-chip"')->assertSee('final question');
    });

    it('has the number in the sentence for Arabic too, in Western digits', function () {
        [$room] = typedGame('closest_number');
        typedPhone($room, 'ar')->call('guess', '١٩٦٦');
        typedReveal($room);

        $html = typedPhone($room->fresh(), 'ar')->html();

        expect($html)->toContain('1966')->toContain('1969')->and(preg_match('/[٠-٩]/u', $html))->toBe(0);
    });
});

describe('the fun fact on the phone', function () {
    it('shows after the answer on any reveal, in the player\'s language, never before', function (string $type, string $locale, string $fact) {
        [$room, $question] = typedGame($type);
        $page = typedPhone($room, $locale);
        $page->assertDontSee($fact)->assertDontSeeHtml('data-test="fun-fact"');

        typedReveal($room);
        typedPhone($room->fresh(), $locale)->assertSeeHtml('data-test="fun-fact"')->assertSee($fact)->assertSee($locale === 'ar' ? 'معلومة طريفة' : 'Fun fact');
    })->with([
        'a choice, in English' => ['multiple_choice', 'en', 'A fun fact.'],
        'a choice, in Arabic' => ['multiple_choice', 'ar', 'معلومة طريفة.'],
        'true or false' => ['true_false', 'en', 'A fun fact.'],
        'a number' => ['closest_number', 'ar', 'معلومة طريفة.'],
    ]);
});
