<?php

use App\Enums\Difficulty;
use App\Game\QuestionPool;
use App\Game\RoomSettings;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\User;
use App\Support\CategoryStyle;
use Database\Seeders\AdminSeeder;
use Database\Seeders\ContentSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DevSeeder;
use Illuminate\Support\Facades\Blade;

/** The shipped content file, as the seeder reads it. */
function contentData(): array
{
    static $data;

    return $data ??= require __DIR__.'/../../database/seeders/data/questions.php'; // no app yet when a dataset is built
}

/** A question's two texts and its options as [ar, en] pairs (correct first), the way ContentSeeder splits them. */
function splitOptions(array $options): array
{
    return array_map(fn (string $option) => str_contains($option, '|') ? explode('|', $option, 2) : [$option, $option], $options);
}

function inProduction(): void
{
    app()['env'] = 'production';
}

describe('the content file', function () {
    it('has at least 200 questions in at least 8 categories', function () {
        $questions = collect(contentData()['questions'])->flatten(1);

        expect($questions->count())->toBeGreaterThanOrEqual(200)
            ->and(count(contentData()['categories']))->toBeGreaterThanOrEqual(8)
            ->and(array_keys(contentData()['questions']))->toEqual(array_keys(contentData()['categories']));
    });

    it('keeps the 40/40/20 mix, roughly', function () {
        $questions = collect(contentData()['questions'])->flatten(1);
        $share = fn (string $difficulty) => $questions->where(0, $difficulty)->count() / $questions->count();

        expect($share('easy'))->toBeBetween(0.35, 0.45)->and($share('medium'))->toBeBetween(0.35, 0.45)->and($share('hard'))->toBeBetween(0.15, 0.25);
    });

    it('has at least 10 questions for every category and difficulty a host can choose', function (string $slug, string $difficulty) {
        $count = collect(contentData()['questions'][$slug])->where(0, $difficulty)->count();

        expect($count)->toBeGreaterThanOrEqual(10);
    })->with(fn () => collect(array_keys(contentData()['categories']))->crossJoin(['easy', 'medium', 'hard'])->all());

    it('gives every question four options, a text in both languages, and an existing difficulty', function () {
        foreach (contentData()['questions'] as $slug => $questions) {
            foreach ($questions as $index => [$difficulty, $ar, $en, $options]) {
                $where = "{$slug} #{$index}: {$en}";

                expect(Difficulty::tryFrom($difficulty))->not->toBeNull($where);
                expect(trim($ar))->not->toBe('', $where)->and(trim($en))->not->toBe('', $where);
                expect(preg_match('/\p{Arabic}/u', $ar))->toBe(1, $where.' (Arabic text)');
                expect(preg_match('/[A-Za-z]/', $en))->toBe(1, $where.' (English text)');
                expect($options)->toHaveCount(4, $where);

                $pairs = splitOptions($options);
                foreach ($pairs as [$optionAr, $optionEn]) {
                    expect(trim($optionAr))->not->toBe('', $where)->and(trim($optionEn))->not->toBe('', $where);
                }
                foreach ($options as $option) {
                    expect(substr_count($option, '|'))->toBeLessThanOrEqual(1, $where.' (one | per option)');
                }
                // Four different answers, in each language.
                expect(count(array_unique(array_map(fn ($pair) => mb_strtolower(trim($pair[0])), $pairs))))->toBe(4, $where.' (distinct Arabic options)');
                expect(count(array_unique(array_map(fn ($pair) => mb_strtolower(trim($pair[1])), $pairs))))->toBe(4, $where.' (distinct English options)');
            }
        }
    });

    it('has no duplicate question text in either language', function () {
        $questions = collect(contentData()['questions'])->flatten(1);

        $normalise = fn (string $text) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text)));
        expect($questions->map(fn ($q) => $normalise($q[1]))->duplicates()->all())->toBe([])
            ->and($questions->map(fn ($q) => $normalise($q[2]))->duplicates()->all())->toBe([]);
    });

    it('uses Western digits only, like the rest of the app', function () {
        $text = json_encode(contentData(), JSON_UNESCAPED_UNICODE);

        expect(preg_match('/[٠-٩۰-۹]/u', $text))->toBe(0);
    });

    it('asks nothing that goes out of date', function () {
        // ("electric current" is physics, not a time claim.)
        $stale = '/\b((?<!electric )current|currently|latest|newest|recent|recently|nowadays|at present|this year|last year|next year|so far|as of)\b/i';

        foreach (contentData()['questions'] as $slug => $questions) {
            foreach ($questions as [, , $en]) {
                expect(preg_match($stale, $en))->toBe(0, "{$slug}: {$en}");
            }
        }
    });

    it('has an icon and a tint for every category, so no tile falls back to the sparkles', function (string $slug) {
        $icon = CategoryStyle::icon($slug);

        expect($icon)->not->toBe('sparkles')
            ->and(CategoryStyle::tint($slug))->toStartWith('bg-tint-')
            ->and(Blade::render('<x-lamma.icon name="'.$icon.'" />'))->not->toBe(Blade::render('<x-lamma.icon name="sparkles" />'));
    })->with(fn () => array_keys(contentData()['categories']));
});

describe('seeding the content', function () {
    it('stores every question with four options, exactly one of them correct, in both languages', function () {
        (new ContentSeeder)->run();

        $total = collect(contentData()['questions'])->flatten(1)->count();
        expect(Category::count())->toBe(count(contentData()['categories']))->and(Question::count())->toBe($total)->and(QuestionOption::count())->toBe($total * 4);

        $questions = Question::with('options')->get();
        foreach ($questions as $question) {
            expect($question->options)->toHaveCount(4);
            expect($question->options->where('is_correct', true))->toHaveCount(1);
            expect($question->getTranslation('text', 'ar'))->not->toBe('')->and($question->getTranslation('text', 'en'))->not->toBe('');
            foreach ($question->options as $option) {
                expect($option->getTranslation('text', 'ar'))->not->toBe('')->and($option->getTranslation('text', 'en'))->not->toBe('');
            }
            expect($question->options->pluck('sort_order')->all())->toBe([1, 2, 3, 4]);
        }
    });

    it('puts the right answer in every position, not always the first', function () {
        (new ContentSeeder)->run();

        $positions = Question::with('options')->get()->map(fn ($q) => $q->options->search(fn ($o) => $o->is_correct))->countBy();

        expect($positions->keys()->sort()->values()->all())->toBe([0, 1, 2, 3]);
        foreach ($positions as $count) {
            expect($count)->toBeGreaterThan(50);
        }
    });

    it('is idempotent: a second run adds nothing', function () {
        (new ContentSeeder)->run();
        $counts = [Category::count(), Question::count(), QuestionOption::count()];
        $ids = Question::pluck('id')->all();

        (new ContentSeeder)->run();

        expect([Category::count(), Question::count(), QuestionOption::count()])->toBe($counts)->and(Question::pluck('id')->all())->toBe($ids);
    });

    it('only adds what is missing and never touches what an admin changed', function () {
        (new ContentSeeder)->run();

        $science = Category::where('slug', 'science')->firstOrFail();
        $science->update(['name' => ['ar' => 'العلوم', 'en' => 'Sciences'], 'is_active' => false]);
        $edited = Question::where('category_id', $science->id)->first();
        $edited->update(['is_active' => false]);
        $removed = Question::where('category_id', $science->id)->skip(1)->first();
        $removed->delete();                                                               // soft-deleted by an admin: stays gone
        $lost = Question::where('category_id', $science->id)->skip(2)->first();
        $lost->options()->delete();
        $lost->forceDelete();                                                             // really missing: comes back
        $custom = Question::create(['category_id' => $science->id, 'text' => ['ar' => 'سؤال خاص؟', 'en' => 'A custom question?'], 'difficulty' => Difficulty::Easy]);

        (new ContentSeeder)->run();

        expect($science->fresh()->getTranslation('name', 'en'))->toBe('Sciences')->and($science->fresh()->is_active)->toBeFalse()
            ->and($edited->fresh()->is_active)->toBeFalse()
            ->and(Question::withTrashed()->find($removed->id)->trashed())->toBeTrue()
            ->and(Question::where('category_id', $science->id)->get()->map->getTranslation('text', 'en')->contains($lost->getTranslation('text', 'en')))->toBeTrue()
            ->and($custom->fresh())->not->toBeNull()
            ->and(Question::where('category_id', $science->id)->count())->toBe(50);       // 50 shipped, one soft-deleted, one re-added, one custom
    });

    it('leaves every category on every difficulty with a full 10-question game', function () {
        (new ContentSeeder)->run();
        $pool = app(QuestionPool::class);

        foreach (Category::all() as $category) {
            foreach ([Difficulty::Easy, Difficulty::Medium, Difficulty::Hard] as $difficulty) {
                $settings = new RoomSettings(categoryIds: [$category->id], questionCount: 10, difficulty: $difficulty);

                expect($pool->isEnough($settings))->toBeTrue("{$category->slug} {$difficulty->value}");
            }
        }
        expect($pool->isEnough(new RoomSettings(categoryIds: Category::pluck('id')->all(), questionCount: 20)))->toBeTrue();
    });

    it('seeds the 8 categories in order, active', function () {
        (new ContentSeeder)->run();

        expect(Category::orderBy('sort_order')->pluck('slug')->all())->toBe(array_keys(contentData()['categories']))
            ->and(Category::where('is_active', false)->count())->toBe(0);
    });
});

describe('the seeders by environment', function () {
    it('locally: the admin, the content and the test players, and running it again is harmless', function () {
        config(['admin.email' => 'admin@lamma.test', 'admin.password' => 'whatever']);

        (new DatabaseSeeder)->run();
        (new DatabaseSeeder)->run();

        expect(User::where('email', 'test@example.com')->value('preferred_locale'))->toBe('ar')
            ->and(User::where('email', 'english@example.com')->value('preferred_locale'))->toBe('en')
            ->and(User::count())->toBe(5)
            ->and(Admin::where('email', 'admin@lamma.test')->count())->toBe(1)
            ->and(Question::count())->toBeGreaterThanOrEqual(200);
    });

    it('in production: the content only', function () {
        inProduction();
        config(['admin.email' => 'admin@lamma.test', 'admin.password' => 'whatever']);

        (new DatabaseSeeder)->run();

        expect(User::count())->toBe(0)->and(Admin::count())->toBe(0)->and(Question::count())->toBeGreaterThanOrEqual(200);
    });

    it('never creates the test players in production, even when asked by name', function () {
        inProduction();

        (new DevSeeder)->run();

        expect(User::count())->toBe(0);
    });

    it('refuses to create an admin in production without credentials', function (?string $email, ?string $password) {
        inProduction();
        config(['admin.email' => $email, 'admin.password' => $password]);

        expect(fn () => (new AdminSeeder)->run())->toThrow(RuntimeException::class, 'AdminSeeder refused');
        expect(Admin::count())->toBe(0);
    })->with([
        'nothing' => [null, null],
        'no password' => ['admin@lamma.test', null],
        'no email' => [null, 'Str0ng!Passw0rd-here'],
        'not an email' => ['admin', 'Str0ng!Passw0rd-here'],
        'too short' => ['admin@lamma.test', 'Sh0rt!pw'],
        'no symbol' => ['admin@lamma.test', 'LongEnough1234Passw'],
        'no number' => ['admin@lamma.test', 'Long-Enough-Passw!'],
        'no capital' => ['admin@lamma.test', 'long-enough-passw0rd!'],
    ]);

    it('creates an active admin in production when the credentials are strong', function () {
        inProduction();
        config(['admin.email' => 'admin@lamma.test', 'admin.password' => 'Str0ng!Passw0rd-here', 'admin.name' => 'Site Admin']);

        (new AdminSeeder)->run();

        $admin = Admin::where('email', 'admin@lamma.test')->firstOrFail();
        expect($admin->name)->toBe('Site Admin')->and($admin->is_active)->toBeTrue()->and(Hash::check('Str0ng!Passw0rd-here', $admin->password))->toBeTrue();
    });

    it('just skips the admin locally when ADMIN_EMAIL or ADMIN_PASSWORD is missing', function () {
        config(['admin.email' => null, 'admin.password' => null]);

        (new AdminSeeder)->run();

        expect(Admin::count())->toBe(0);
    });
});
