<?php

use App\Enums\Difficulty;
use App\Enums\QuestionType;
use App\Enums\RoomStatus;
use App\Game\CategoryVote;
use App\Game\GameEngine;
use App\Game\PlayerPhoto;
use App\Game\QuestionImage;
use App\Game\RoomSettings;
use App\Game\Transition;
use App\Models\Category;
use App\Models\CategoryVoteRound;
use App\Models\PlayerAnswer;
use App\Models\Question;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\RoomQuestion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Browser');

// Selfies never touch the real folder in a test.
pest()->beforeEach(fn () => Storage::fake(PlayerPhoto::DISK))->in('Feature', 'Browser');

// Nor do the pictures of picture questions.
pest()->beforeEach(fn () => Storage::fake(QuestionImage::DISK))->in('Feature', 'Browser');

// Browser tests (tests/Browser, run with `vendor/bin/pest tests/Browser`) load the BUILT assets, never a developer's running Vite dev server.
pest()->beforeEach(fn () => Vite::useHotFile(storage_path('framework/no-hot-file')))->in('Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * An active category holding $count playable questions (active, translated in Arabic and English, 4 options each).
 */
function categoryWithQuestions(int $count, ?Difficulty $difficulty = null, array $category = []): Category
{
    $category = Category::factory()->create($category);

    Question::factory()
        ->count($count)
        ->withOptions()
        ->create(['category_id' => $category->id, ...($difficulty ? ['difficulty' => $difficulty] : [])]);

    return $category;
}

/**
 * A lobby ready to start: a category with enough playable questions, a room set up for $questions of them, and $players connected
 * players who have tapped Ready. $settings overrides RoomSettings fields (secondsPerQuestion, difficulty, ...).
 */
function gameRoom(int $players = 2, int $questions = 3, array $settings = [], ?User $host = null, ?Category $category = null): Room
{
    $category ??= categoryWithQuestions(max(10, $questions + 4));

    $room = Room::factory()->create([
        ...($host ? ['host_id' => $host->id] : []),
        'settings' => new RoomSettings(...[
            'categoryIds' => [$category->id], 'questionCount' => $questions, 'secondsPerQuestion' => 20, ...$settings,
        ]),
    ]);
    RoomPlayer::factory()->for($room)->count($players)->create(['is_ready' => true, 'left_at' => null]);

    return $room;
}

/**
 * A Vote-mode lobby ready to start: $categories (or $pool new top-level categories with $perCategory playable questions each) as the pool, the first
 * one as the first category, a game of $questions voting every $every, and $players connected players who have tapped Ready.
 *
 * @param  list<Category>  $categories
 */
function voteRoom(int $players = 2, int $questions = 20, int $every = 5, int $pool = 3, int $perCategory = 12, array $categories = [], array $settings = [], ?User $host = null): Room
{
    $categories = $categories ?: collect(range(1, $pool))->map(fn () => categoryWithQuestions($perCategory))->all();

    $room = Room::factory()->vote($every)->create([
        ...($host ? ['host_id' => $host->id] : []),
        'first_category_id' => $categories[0]->id,
        'settings' => new RoomSettings(...['questionCount' => $questions, 'secondsPerQuestion' => 20, ...$settings]),
    ]);
    $room->pool()->attach(collect($categories)->pluck('id')->all());
    RoomPlayer::factory()->for($room)->count($players)->create(['is_ready' => true, 'left_at' => null]);

    return $room;
}

function votes(): CategoryVote
{
    return app(CategoryVote::class);
}

/**
 * Play a Vote-mode room (starting it if it is still a lobby) through to its next vote: every open question is revealed and moved on at once,
 * nobody answering. Returns the open vote round.
 */
function playToVote(Room $room): CategoryVoteRound
{
    $engine = app(GameEngine::class);
    if ($room->fresh()->status === RoomStatus::Lobby) {
        startGame($room);
    }

    for ($guard = 0; $guard < 30; $guard++) {
        $question = $room->fresh()->currentQuestion();
        $engine->reveal($room, $question->position, force: true);
        if (($vote = votes()->openRound($room->fresh())) !== null) {
            return $vote;
        }
        if ($engine->advance($room, $question->position, force: true) === Transition::Voting) {
            return votes()->openRound($room->fresh());
        }
    }

    throw new RuntimeException('The game never reached a vote.');
}

/**
 * Play a Vote-mode room (starting it if it is still a lobby) to its end, nobody answering. At each vote $onVote($round) may cast votes; then the host
 * skips the rest of the vote. Returns the finished room.
 */
function finishVoteGame(Room $room, ?Closure $onVote = null): Room
{
    $engine = app(GameEngine::class);
    if ($room->fresh()->status === RoomStatus::Lobby) {
        startGame($room);
    }

    for ($guard = 0; $guard < 100 && $room->fresh()->status === RoomStatus::Playing; $guard++) {
        $question = $room->fresh()->currentQuestion();
        $engine->reveal($room, $question->position, force: true);
        if (($vote = votes()->openRound($room->fresh())) !== null) {
            $onVote?->__invoke($vote);
            $engine->closeVote($room, $vote->round, force: true);
        }
        $engine->advance($room, $question->position, force: true);
    }

    return $room->fresh();
}

/** The top-level category a room question belongs to (its own category, or the parent of its subcategory). */
function topCategoryOf(RoomQuestion $roomQuestion): int
{
    $category = $roomQuestion->question()->with('category')->first()->category;

    return $category->parent_id ?? $category->id;
}

/** Start the room's game as its host and return question 1 (open). The 3 · 2 · 1 · Go! countdown is skipped: question 1 opens right now. */
function startGame(Room $room): RoomQuestion
{
    $engine = app(GameEngine::class);
    $engine->start($room, $room->host);
    $engine->startQuestion($room->fresh(), force: true);

    return $room->fresh()->currentQuestion();
}

/** The id of the correct option (or of the first wrong one) of a room question. */
function optionOf(RoomQuestion $roomQuestion, bool $correct = true): int
{
    return $roomQuestion->question()->with('options')->first()->options
        ->first(fn ($option) => $option->is_correct === $correct)->id;
}

/**
 * A player answers right (true) or wrong (false). A closest-number question is answered with its exact value (right) or a number ten tolerances away (wrong).
 */
function answer(RoomPlayer $player, RoomQuestion $roomQuestion, bool $correct = true): PlayerAnswer
{
    $question = $roomQuestion->question;

    if ($question->type->takesNumber()) {
        return app(GameEngine::class)->submitAnswer($player, $correct ? (float) $question->answer_number : (float) $question->answer_number + 10 * (float) $question->tolerance);
    }

    return app(GameEngine::class)->submitAnswer($player, optionOf($roomQuestion, $correct));
}

/** A player guesses this number (as typed) on a closest-number question. */
function guess(RoomPlayer $player, string|int|float $typed): PlayerAnswer
{
    return app(GameEngine::class)->submitAnswer($player, $typed);
}

/** 64 letters and digits: a guest token. */
function typedToken(): string
{
    return 'ty'.str_repeat('x', 62);
}

/**
 * A lobby whose game has exactly these question types, in this order: each one gets the next difficulty (easy, medium, hard, then hard again), and a Mixed game
 * climbs easy to hard, so question 1 is the first type. A closest-number question is 1969 with a tolerance of 10 unless said otherwise; a
 * true/false one is True; every other kind has four options, the first correct.
 *
 * @param  list<string>  $types  QuestionType values
 */
function typedRoom(array $types = ['multiple_choice', 'true_false', 'closest_number'], int $players = 3, array $settings = [], ?User $host = null, bool $withImage = false, float $number = 1969, float $tolerance = 10): Room
{
    $category = Category::factory()->create();
    $levels = [Difficulty::Easy, Difficulty::Medium, Difficulty::Hard];

    foreach (array_values($types) as $index => $type) {
        $factory = Question::factory()->state(['category_id' => $category->id, 'difficulty' => $levels[min($index, 2)]])->withFact();
        $factory = match (QuestionType::from($type)) {
            QuestionType::TrueFalse => $factory->trueFalse(),
            QuestionType::ClosestNumber => $factory->closestNumber($number, $tolerance),
            QuestionType::MultipleChoice => $factory->withOptions(),
        };
        $factory->when($withImage, fn ($f) => $f->withImage('picture-'.$index.'.webp'))->create();
    }

    return gameRoom($players, count($types), $settings, $host, $category);
}

function at(string $time): void
{
    test()->travelTo(CarbonImmutable::parse("2026-10-01 {$time}"));
}

/** @return array{0: Room, 1: RoomQuestion, 2: Collection<int, RoomPlayer>} a started game with 3 players; the question runs 12:00:00.000 to 12:00:20.000 */
function openQuestion(): array
{
    $room = gameRoom(players: 3);
    $question = startGame($room);

    return [$room, $question, $room->players()->orderBy('id')->get()];
}

/**
 * A game that ran to the end: every question asked and revealed, the room finished, and these players (nickname => final score) in
 * the room, connected. Call it where Event and Queue are faked (starting a game broadcasts and queues).
 */
function finishedGame(array $scores, int $questions = 3, array $settings = [], ?User $host = null): Room
{
    $room = gameRoom(players: 0, questions: $questions, settings: $settings, host: $host);
    foreach (array_keys($scores) as $nickname) {
        RoomPlayer::factory()->for($room)->create(['nickname' => $nickname, 'is_ready' => true, 'left_at' => null]);
    }

    startGame($room);
    $room->roomQuestions()->update(['started_at' => now()->subMinute(), 'ends_at' => now()->subSeconds(40), 'revealed_at' => now()->subSeconds(39)]);
    foreach ($scores as $nickname => $score) {
        $room->players()->where('nickname', $nickname)->update(['score' => $score]);
    }
    $room->update(['status' => RoomStatus::Finished, 'finished_at' => now()]);

    return $room->fresh();
}

/**
 * A finished game this user played as themselves (a row tied to their account) with $correct right answers and the final $score,
 * against $rivals (nickname => score). Returns the room and the user's row. Call it where Event and Queue are faked.
 *
 * @return array{0: Room, 1: RoomPlayer}
 */
function savedGame(User $user, int $score, array $rivals = ['Rival' => 100], int $correct = 0, int $questions = 3, array $settings = []): array
{
    $room = finishedGame($rivals, questions: $questions, settings: $settings);
    $mine = RoomPlayer::factory()->for($room)->forUser($user)->create(['nickname' => 'Me'.$user->id, 'score' => $score, 'is_ready' => true, 'left_at' => null]);

    foreach ($room->roomQuestions()->take($correct)->get() as $roomQuestion) {
        PlayerAnswer::create(['room_question_id' => $roomQuestion->id, 'room_player_id' => $mine->id, 'question_option_id' => null, 'answered_at' => now(), 'is_correct' => true, 'points' => 100]);
    }

    return [$room, $mine];
}

/*
|--------------------------------------------------------------------------
| Selfies
|--------------------------------------------------------------------------
*/

/** The bytes of a real JPEG: $width x $height, left half red and right half blue. */
function jpegBytes(int $width = 640, int $height = 640, int $quality = 85): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, (int) imagecolorallocate($image, 255, 0, 0));
    imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 0, 0, 255));

    ob_start();
    imagejpeg($image, null, $quality);

    return (string) ob_get_clean();
}

function webpBytes(int $width = 300, int $height = 300): string
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagewebp($image, null, 80);

    return (string) ob_get_clean();
}

function upload(string $bytes, string $name = 'selfie.jpg'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $bytes);
}

function photos(): PlayerPhoto
{
    return app(PlayerPhoto::class);
}

/** The pixel of a WebP at x, y as [r, g, b]. */
function pixelOf(string $webp, int $x, int $y): array
{
    $rgb = imagecolorat(imagecreatefromstring($webp), $x, $y);

    return [($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255];
}
