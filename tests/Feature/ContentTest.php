<?php

use App\Enums\Difficulty;
use App\Enums\QuestionType;
use App\Game\QuestionImage;
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
use Illuminate\Support\Facades\Storage;

/** The shipped content, as the seeder reads it: data/questions.php plus one file per category in data/questions/. */
function contentData(): array
{
    static $data;

    return $data ??= ContentSeeder::data();
}

/** A question's two texts and its options as [ar, en] pairs (correct first), the way ContentSeeder splits them. */
function splitOptions(array $options): array
{
    return array_map(fn (string $option) => str_contains($option, '|') ? explode('|', $option, 2) : [$option, $option], $options);
}

/** The kind of a row of the content data: its extras (a fifth element) name it, and a plain row is the multiple-choice question it always was. */
function rowType(array $row): string
{
    return $row[4]['type'] ?? 'multiple_choice';
}

/** The options of a multiple-choice row as [ar, en] pairs; none for the other kinds, whose fourth element is a true/false or a number. */
function rowPairs(array $row): array
{
    return rowType($row) === 'multiple_choice' ? splitOptions($row[3]) : [];
}

/** The fun fact of a row as [ar, en], or null. */
function rowFact(array $row): ?array
{
    return isset($row[4]['fact']) ? explode('|', $row[4]['fact'], 2) : null;
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
            ->and(array_keys(contentData()['questions']))->toEqualCanonicalizing(array_keys(contentData()['categories']));
    });

    it('keeps a healthy mix of difficulties', function () {
        $questions = collect(contentData()['questions'])->flatten(1);
        $share = fn (string $difficulty) => $questions->where(0, $difficulty)->count() / $questions->count();

        expect($share('easy'))->toBeBetween(0.25, 0.45)->and($share('medium'))->toBeBetween(0.25, 0.45)->and($share('hard'))->toBeBetween(0.15, 0.40);
    });

    it('has at least 1,200 questions', function () {
        expect(collect(contentData()['questions'])->flatten(1)->count())->toBeGreaterThanOrEqual(1200);
    });

    it('has the subcategories of Sports and General Knowledge, each under its parent', function () {
        $categories = contentData()['categories'];
        $sub = fn (string $parent) => array_keys(array_filter($categories, fn (array $category) => ($category['parent'] ?? null) === $parent));

        expect($sub('sports'))->toEqualCanonicalizing(['football', 'basketball', 'rugby', 'tennis', 'motorsport', 'olympics', 'combat-sports'])
            ->and($sub('general-knowledge'))->toEqualCanonicalizing(['everyday-life', 'words-language', 'inventions', 'human-body', 'landmarks', 'arts-music']);
        foreach ($categories as $slug => $category) {
            if (isset($category['parent'])) {
                expect($categories[$category['parent']] ?? null)->not->toBeNull("{$slug}: its parent exists")
                    ->and($categories[$category['parent']]['parent'] ?? null)->toBeNull("{$slug}: its parent is top-level (one level only)");
            }
        }
    });

    it('meets the targets: a subcategory has 40 questions with 10 of each difficulty, a category without subcategories 80 with 20 of each', function () {
        $categories = contentData()['categories'];
        $hasChildren = collect($categories)->pluck('parent')->filter()->unique()->all();

        foreach ($categories as $slug => $category) {
            $questions = collect(contentData()['questions'][$slug] ?? []);
            $target = isset($category['parent']) ? config('lamma.content.subcategory') : (in_array($slug, $hasChildren, true) ? null : config('lamma.content.category'));
            $target ??= ['total' => 1, 'each' => 0]; // a parent keeps a handful of direct questions

            expect($questions->count())->toBeGreaterThanOrEqual($target['total'], "{$slug} total");
            foreach (['easy', 'medium', 'hard'] as $difficulty) {
                expect($questions->where(0, $difficulty)->count())->toBeGreaterThanOrEqual($target['each'], "{$slug} {$difficulty}");
            }
        }
    });

    it('has at least 10 questions for every category and difficulty a host can choose', function (string $slug, string $difficulty) {
        $count = collect(contentData()['questions'][$slug])->where(0, $difficulty)->count();

        expect($count)->toBeGreaterThanOrEqual(10);
    })->with(fn () => collect(array_keys(contentData()['categories']))->crossJoin(['easy', 'medium', 'hard'])->all());

    it('gives every question a text in both languages, an existing difficulty and what its kind needs: four options, true or false, or a number', function () {
        foreach (contentData()['questions'] as $slug => $questions) {
            foreach ($questions as $index => $row) {
                [$difficulty, $ar, $en, $answer] = $row;
                $type = rowType($row);
                $where = "{$slug} #{$index}: {$en}";

                expect(Difficulty::tryFrom($difficulty))->not->toBeNull($where);
                expect(trim($ar))->not->toBe('', $where)->and(trim($en))->not->toBe('', $where);
                expect(preg_match('/\p{Arabic}/u', $ar))->toBe(1, $where.' (Arabic text)');
                expect(preg_match('/[A-Za-z]/', $en))->toBe(1, $where.' (English text)');
                expect(QuestionType::tryFrom($type))->not->toBeNull($where.' (a known type)');

                if ($type === 'multiple_choice') {
                    expect($answer)->toBeArray($where)->toHaveCount(4, $where);
                    $pairs = splitOptions($answer);
                    foreach ($pairs as [$optionAr, $optionEn]) {
                        expect(trim($optionAr))->not->toBe('', $where)->and(trim($optionEn))->not->toBe('', $where);
                    }
                    foreach ($answer as $option) {
                        expect(substr_count($option, '|'))->toBeLessThanOrEqual(1, $where.' (one | per option)');
                    }
                    // Four different answers, in each language.
                    expect(count(array_unique(array_map(fn ($pair) => mb_strtolower(trim($pair[0])), $pairs))))->toBe(4, $where.' (distinct Arabic options)');
                    expect(count(array_unique(array_map(fn ($pair) => mb_strtolower(trim($pair[1])), $pairs))))->toBe(4, $where.' (distinct English options)');
                } elseif ($type === 'true_false') {
                    expect($answer)->toBeBool($where.' (true or false)');
                } else {
                    expect(is_int($answer) || is_float($answer))->toBeTrue($where.' (a number)');
                    expect(abs((float) $answer))->toBeLessThanOrEqual(1_000_000_000_000);
                }

                // The extras: only these, and each one well-formed.
                $extras = $row[4] ?? [];
                expect(array_diff(array_keys($extras), ['type', 'tolerance', 'image', 'fact']))->toBe([], $where.' (known extras only)');
                if (isset($extras['tolerance'])) {
                    expect($type)->toBe('closest_number', $where.' (a tolerance is for a number)')->and($extras['tolerance'])->toBeGreaterThan(0);
                }
                if (isset($extras['fact'])) {
                    expect(substr_count($extras['fact'], '|'))->toBe(1, $where.' (a fact is "arabic|english")');
                    [$factAr, $factEn] = rowFact($row);
                    expect(preg_match('/\p{Arabic}/u', $factAr))->toBe(1, $where.' (Arabic fact)')->and(preg_match('/[A-Za-z]/', $factEn))->toBe(1, $where.' (English fact)')
                        ->and(mb_strlen($factAr))->toBeLessThanOrEqual(300)->and(mb_strlen($factEn))->toBeLessThanOrEqual(300);
                }
                if (isset($extras['image'])) {
                    expect(is_file(database_path('seeders/data/images/'.$extras['image'])))->toBeTrue($where.' (the picture file exists)');
                }
            }
        }
    });

    it('has no duplicate question text in either language', function () {
        $questions = collect(contentData()['questions'])->flatten(1);

        $normalise = fn (string $text) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text)));
        expect($questions->map(fn ($q) => $normalise($q[1]))->duplicates()->all())->toBe([])
            ->and($questions->map(fn ($q) => $normalise($q[2]))->duplicates()->all())->toBe([]);
    });

    it('has no garbled text: no word mixing Arabic and Latin letters, no Persian letters, no double spaces, no Arabic in English', function () {
        foreach (contentData()['questions'] as $slug => $questions) {
            foreach ($questions as $index => $row) {
                [, $ar, $en] = $row;
                $pairs = rowPairs($row);
                $fact = rowFact($row);
                $arabic = [$ar, ...array_column($pairs, 0), ...($fact ? [$fact[0]] : [])];
                $english = [$en, ...array_column($pairs, 1), ...($fact ? [$fact[1]] : [])];

                foreach ($arabic as $text) {
                    foreach (preg_split('/[\s().,;:\[\]«»"\-\/–؟،]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) as $word) {
                        expect(preg_match('/\p{Arabic}/u', $word) === 1 && preg_match('/[A-Za-z]/', $word) === 1)->toBeFalse("{$slug} #{$index}: mixed scripts in \"{$word}\" ({$text})");
                    }
                    expect(preg_match('/[\x{06CC}\x{06A9}\x{06C1}\x{06BE}]/u', $text))->toBe(0, "{$slug} #{$index}: a Persian letter in \"{$text}\"");
                }
                foreach ([...$arabic, ...$english] as $text) {
                    expect(str_contains($text, '  '))->toBeFalse("{$slug} #{$index}: double space in \"{$text}\"");
                }
                foreach ($english as $text) {
                    expect(preg_match('/\p{Arabic}/u', $text))->toBe(0, "{$slug} #{$index}: Arabic letters in \"{$text}\"");
                }
            }
        }
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
    it('stores every question complete for its kind and in both languages: four options with one right, True and False, or a number and no options', function () {
        (new ContentSeeder)->run();

        $rows = collect(contentData()['questions'])->flatten(1);
        $ofType = fn (string $type) => $rows->filter(fn (array $row) => rowType($row) === $type)->count();
        expect(Category::count())->toBe(count(contentData()['categories']))->and(Question::count())->toBe($rows->count())
            ->and(QuestionOption::count())->toBe($ofType('multiple_choice') * 4 + $ofType('true_false') * 2);

        foreach (Question::with('options')->get() as $question) {
            expect($question->problems())->toBe([], 'question '.$question->getTranslation('text', 'en'));
            expect($question->getTranslation('text', 'ar'))->not->toBe('')->and($question->getTranslation('text', 'en'))->not->toBe('');
            foreach ($question->options as $option) {
                expect($option->getTranslation('text', 'ar'))->not->toBe('')->and($option->getTranslation('text', 'en'))->not->toBe('');
            }
            expect($question->options->where('is_correct', true))->toHaveCount($question->type === QuestionType::ClosestNumber ? 0 : 1);
            expect($question->options->pluck('sort_order')->all())->toBe(match ($question->type) {
                QuestionType::MultipleChoice => [1, 2, 3, 4],
                QuestionType::TrueFalse => [1, 2],
                QuestionType::ClosestNumber => [],
            });
        }
    });

    it('puts the right answer in every position, not always the first', function () {
        (new ContentSeeder)->run();

        $positions = Question::with('options')->where('type', QuestionType::MultipleChoice->value)->get()->map(fn ($q) => $q->options->search(fn ($o) => $o->is_correct))->countBy();

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
            ->and(Question::where('category_id', $science->id)->count())->toBe(count(contentData()['questions']['science']));       // all shipped, one soft-deleted, one re-added, one custom
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

    it('seeds the top-level categories in order, active', function () {
        (new ContentSeeder)->run();

        $topLevel = array_keys(array_filter(contentData()['categories'], fn (array $category) => ! isset($category['parent'])));
        expect(Category::topLevel()->orderBy('sort_order')->pluck('slug')->all())->toBe($topLevel)
            ->and(Category::where('is_active', false)->count())->toBe(0);
    });
});

describe('the variety pack: true/false, closest-number and picture questions', function () {
    $ofType = fn (string $type) => collect(contentData()['questions'])->flatten(1)->filter(fn (array $row) => rowType($row) === $type);
    $pictures = fn () => collect(contentData()['questions'])->flatten(1)->filter(fn (array $row) => isset($row[4]['image']));

    it('ships at least 12 true/false, 10 closest-number and 8 picture questions', function () use ($ofType, $pictures) {
        expect($ofType('true_false')->count())->toBeGreaterThanOrEqual(12)->and($ofType('closest_number')->count())->toBeGreaterThanOrEqual(10)->and($pictures()->count())->toBeGreaterThanOrEqual(8);
    });

    it('has a fun fact for most of them, and a tolerance for every year', function () use ($ofType) {
        $withFacts = collect(contentData()['questions'])->flatten(1)->filter(fn (array $row) => rowType($row) !== 'multiple_choice')->filter(fn (array $row) => rowFact($row) !== null);

        expect($withFacts->count())->toBeGreaterThanOrEqual(20);
        foreach ($ofType('closest_number') as $row) {
            // A tolerance of a quarter of the number suits a count; a year needs its own, small one.
            if (str_contains(strtolower($row[2]), 'which year')) {
                expect($row[4]['tolerance'] ?? null)->not->toBeNull($row[2])->and($row[4]['tolerance'])->toBeLessThanOrEqual(25);
            }
        }
    });

    it('is drawn by us: every picture is a small SVG with no script, no link and no font file, and every one is used', function () use ($pictures) {
        $used = $pictures()->map(fn (array $row) => $row[4]['image'])->unique();
        $files = collect(glob(database_path('seeders/data/images/*')))->map(fn ($file) => basename($file));

        expect($files->sort()->values()->all())->toBe($used->sort()->values()->all());
        foreach ($files as $file) {
            $svg = file_get_contents(database_path("seeders/data/images/{$file}"));

            expect($file)->toEndWith('.svg')->and(strlen($svg))->toBeLessThan(20 * 1024)->and($svg)->toStartWith('<svg xmlns="http://www.w3.org/2000/svg"')->toContain('viewBox="0 0 400 300"')
                ->and(preg_match('/<script|<foreignObject|<image|<use|<style|<!ENTITY|<!DOCTYPE|javascript:|data:|\bon[a-z]+\s*=|href\s*=|@import|url\(/i', $svg))->toBe(0, $file);
            expect(simplexml_load_string($svg))->not->toBeFalse($file.' is well-formed XML');
        }
    });

    it('seeds them: the right types, the pictures on the pictures disk, a tolerance on every number', function () {
        (new ContentSeeder)->run();

        $byType = Question::query()->get()->groupBy(fn (Question $question) => $question->type->value);
        expect($byType['true_false']->count())->toBeGreaterThanOrEqual(12)->and($byType['closest_number']->count())->toBeGreaterThanOrEqual(10);
        foreach ($byType['closest_number'] as $number) {
            expect($number->answer_number)->not->toBeNull()->and($number->tolerance)->toBeGreaterThan(0);
        }

        $withPicture = Question::query()->whereNotNull('image_path')->get();
        expect($withPicture->count())->toBeGreaterThanOrEqual(8);
        foreach ($withPicture as $question) {
            expect($question->image_path)->toStartWith('seed/')->toEndWith('.svg')->and(Storage::disk(QuestionImage::DISK)->exists($question->image_path))->toBeTrue()
                ->and($question->imageUrl())->toEndWith(basename($question->image_path));
        }
    });

    it('is safe to run again: nothing is added twice, nothing an admin changed is changed back', function () {
        (new ContentSeeder)->run();
        $counts = [Question::count(), QuestionOption::count(), count(Storage::disk(QuestionImage::DISK)->allFiles())];
        $flag = Question::where('type', QuestionType::TrueFalse->value)->first();
        $flag->update(['is_active' => false]);
        $edited = Question::where('type', QuestionType::ClosestNumber->value)->first();
        $edited->update(['answer_number' => 12345, 'tolerance' => 3]);

        (new ContentSeeder)->run();

        expect([Question::count(), QuestionOption::count(), count(Storage::disk(QuestionImage::DISK)->allFiles())])->toBe($counts)
            ->and($flag->fresh()->is_active)->toBeFalse()->and($edited->fresh()->answer_number)->toBe(12345.0)->and($edited->fresh()->tolerance)->toBe(3.0);
    });

    it('can play: a game on a category the pack touches draws all three kinds', function () {
        (new ContentSeeder)->run();
        $pool = app(QuestionPool::class);

        foreach (Category::query()->topLevel()->get() as $category) {
            $types = Question::query()->playable(['ar', 'en'])->whereIn('category_id', $pool->categories([$category->id]))->get()->pluck('type.value')->unique();
            expect($types->count())->toBeGreaterThanOrEqual(1);
        }
        $all = Question::query()->playable(['ar', 'en'])->get()->pluck('type.value')->unique()->sort()->values()->all();
        expect($all)->toBe(['closest_number', 'multiple_choice', 'true_false']);
    });

    it('reads a row with a fifth element, and a row without one, from a given set of data', function () {
        (new ContentSeeder)->withData([
            'categories' => ['maths' => ['ar' => 'رياضيات', 'en' => 'Maths', 'icon' => 'x']],
            'questions' => ['maths' => [
                ['easy', 'سؤال قديم؟', 'An old kind?', ['أ|A', 'ب|B', 'ج|C', 'د|D']],
                ['easy', 'صح؟', 'Is it so?', false, ['type' => 'true_false', 'fact' => 'نعم|Yes']],
                ['medium', 'كم؟', 'How many?', 12, ['type' => 'closest_number']],
                ['medium', 'كم سنة؟', 'Which year?', 1990, ['type' => 'closest_number', 'tolerance' => 4]],
                ['hard', 'ما هذا؟', 'What is it?', ['أ|A', 'ب|B', 'ج|C', 'د|D'], ['image' => 'shape-hexagon.svg']],
            ]],
        ])->run();

        $questions = Question::with('options')->orderBy('id')->get();
        expect($questions->pluck('type.value')->all())->toBe(['multiple_choice', 'true_false', 'closest_number', 'closest_number', 'multiple_choice'])
            ->and($questions[1]->options->firstWhere('is_correct', true)->getTranslation('text', 'en'))->toBe('False')->and($questions[1]->getTranslation('explanation', 'ar'))->toBe('نعم')
            ->and($questions[2]->tolerance)->toBe(3.0)->and($questions[3]->tolerance)->toBe(4.0)->and($questions[4]->image_path)->toBe('seed/shape-hexagon.svg')
            ->and($questions->every(fn (Question $question) => $question->problems() === []))->toBeTrue();
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
