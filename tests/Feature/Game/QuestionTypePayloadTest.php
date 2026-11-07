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
use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Event::fake([GameStarted::class, QuestionStarted::class, PlayerAnswered::class, QuestionRevealed::class, ScoreboardUpdated::class, GameFinished::class]);
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00.000'));
});

/** A one-question game of this type. The number (7351) and the tolerance (37.5) are ones that cannot turn up in a timestamp, an id or a class name. */
function secretGame(string $type, bool $withImage = false): Room
{
    $room = typedRoom([$type], players: 3, settings: ['doubleFinal' => false], withImage: $withImage, number: 7351, tolerance: 37.5);
    startGame($room);

    return $room;
}

/** Every key, at any depth, of a payload. */
function payloadKeys(array $payload): array
{
    $keys = [];
    array_walk_recursive($payload, fn () => null);
    $walk = function (array $node) use (&$walk, &$keys) {
        foreach ($node as $key => $value) {
            $keys[] = (string) $key;
            if (is_array($value)) {
                $walk($value);
            }
        }
    };
    $walk($payload);

    return $keys;
}

/** Everything that would give the answer away, or that only the reveal may say. */
function secrets(): array
{
    return ['7351', '37.5', 'answer_number', 'tolerance', 'is_correct', 'correct_option_id', 'correct_number', 'A fun fact.', 'معلومة طريفة'];
}

describe('before the reveal, no payload gives the answer away', function () {
    it('keeps the answer, the tolerance and the fun fact out of QuestionStarted, whatever the type', function (string $type) {
        $room = secretGame($type, withImage: true);

        $payload = Event::dispatched(QuestionStarted::class)->last()[0]->broadcastWith();
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $keys = payloadKeys($payload);

        foreach (secrets() as $secret) {
            expect($json)->not->toContain($secret);
        }
        expect($keys)->not->toContain('is_correct')->not->toContain('correct')->not->toContain('answer')->not->toContain('answer_number')->not->toContain('tolerance')->not->toContain('explanation')
            ->and($payload['question']['type'])->toBe($type)
            ->and($payload['question']['image_url'])->toContain('picture-0.webp')
            ->and($room->exists)->toBeTrue();
    })->with(['multiple_choice', 'true_false', 'closest_number']);

    it('carries the options of a choice question and none for a number', function (string $type, int $options) {
        secretGame($type);

        $payload = Event::dispatched(QuestionStarted::class)->last()[0]->broadcastWith();

        expect($payload['question']['options'])->toHaveCount($options);
        foreach ($payload['question']['options'] as $option) {
            expect($option)->toHaveKeys(['id', 'text'])->and(array_keys($option))->toBe(['id', 'text']);
        }
    })->with([['multiple_choice', 4], ['true_false', 2], ['closest_number', 0]]);

    it('has no picture address on a question without a picture', function () {
        secretGame('true_false');

        expect(Event::dispatched(QuestionStarted::class)->last()[0]->broadcastWith()['question']['image_url'])->toBeNull();
    });

    it('says only who has answered, never what', function (string $type) {
        $room = secretGame($type);
        $players = $room->players()->orderBy('id')->get();
        answer($players[0], $room->currentQuestion());

        $json = json_encode(Event::dispatched(PlayerAnswered::class)->last()[0]->broadcastWith());

        foreach (['7351', 'option', 'value', 'guess', 'correct'] as $secret) {
            expect($json)->not->toContain($secret);
        }
    })->with(['multiple_choice', 'true_false', 'closest_number']);
});

describe('before the reveal, no screen gives the answer away', function () {
    it('keeps it out of the host screen\'s page, whatever the type and the language', function (string $type, string $mode) {
        $room = secretGame($type, withImage: true);
        $settings = clone $room->settings;
        $settings->hostScreenLocale = HostScreenLocale::from($mode);
        $room->update(['settings' => $settings]);
        answer($room->players()->orderBy('id')->first(), $room->currentQuestion());

        $html = Livewire::actingAs($room->host)->test(HostGame::class, ['room' => $room->fresh()])->html();

        foreach (secrets() as $secret) {
            expect($html)->not->toContain($secret);
        }
        expect($html)->not->toContain('data-correct="true"');
    })->with(['multiple_choice', 'true_false', 'closest_number'])->with(['both', 'en', 'ar']);

    it('keeps it out of a phone, in both languages, even after that phone has answered', function (string $type, string $locale, bool $answered) {
        $room = secretGame($type);
        $me = $room->players()->orderBy('id')->first();
        $me->update(['guest_token' => typedToken(), 'locale' => $locale]);
        if ($answered) {
            answer($me, $room->currentQuestion(), correct: false);
        }

        $html = Livewire::withCookie('lamma_guest', typedToken())->test(PlayerGame::class, ['room' => $room])->html();

        foreach (secrets() as $secret) {
            expect($html)->not->toContain($secret);
        }
        expect($html)->not->toContain('data-correct');
    })->with(['multiple_choice', 'true_false', 'closest_number'])->with(['en', 'ar'])->with([false, true]);

    it('does not show one phone what another guessed', function () {
        $room = secretGame('closest_number');
        $players = $room->players()->orderBy('id')->get();
        $players[0]->update(['guest_token' => typedToken()]);
        guess($players[1], 7340);

        $html = Livewire::withCookie('lamma_guest', typedToken())->test(PlayerGame::class, ['room' => $room])->html();

        expect($html)->not->toContain('7340');
    });

    it('says all of it, and only now, once the question is revealed', function (string $type) {
        $room = secretGame($type);
        app(GameEngine::class)->reveal($room, 1, force: true);

        $payload = Event::dispatched(QuestionRevealed::class)->last()[0]->broadcastWith();
        $page = Livewire::actingAs($room->host)->test(HostGame::class, ['room' => $room->fresh()]);

        expect($payload['explanation'])->toBe(['ar' => 'معلومة طريفة.', 'en' => 'A fun fact.']);
        $page->assertSee('A fun fact.');
        if ($type === 'closest_number') {
            expect($payload['correct_number'])->toBe(7351.0);
            $page->assertSee('7351');
        } else {
            expect($payload['correct_option_id'])->not->toBeNull();
        }
    })->with(['multiple_choice', 'true_false', 'closest_number']);
});
