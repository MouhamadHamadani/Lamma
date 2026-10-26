<?php

use App\Enums\Difficulty;
use App\Game\QuestionReport;
use App\Models\Category;
use App\Models\Question;
use App\Support\SeedKey;
use Database\Seeders\ContentSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** A tiny content set: Sports with Football and Tennis below it, and Science. */
function smallContent(array $questions = []): array
{
    $question = fn (string $en, string $difficulty = 'easy') => [$difficulty, 'سؤال: '.$en, $en, ['أ|A', 'ب|B', 'ج|C', 'د|D']];

    return [
        'categories' => [
            'sports' => ['ar' => 'رياضة', 'en' => 'Sports', 'icon' => 'x'],
            'science' => ['ar' => 'علوم', 'en' => 'Science', 'icon' => 'x'],
            'football' => ['ar' => 'كرة القدم', 'en' => 'Football', 'icon' => 'x', 'parent' => 'sports'],
            'tennis' => ['ar' => 'التنس', 'en' => 'Tennis', 'icon' => 'x', 'parent' => 'sports'],
        ],
        'questions' => $questions ?: [
            'sports' => [$question('A direct sports question?')],
            'football' => [$question('Who scores in football?'), $question('How long is a half?', 'medium')],
            'tennis' => [$question('What is a love score?')],
            'science' => [$question('What is H2O?')],
        ],
    ];
}

function seedSmall(?array $content = null): void
{
    (new ContentSeeder)->withData($content ?? smallContent())->run();
}

describe('subcategories in the seeder', function () {
    it('creates a subcategory under its parent, with the questions where they belong', function () {
        seedSmall();

        $sports = Category::where('slug', 'sports')->firstOrFail();
        $football = Category::where('slug', 'football')->firstOrFail();
        expect($football->parent_id)->toBe($sports->id)->and($football->getTranslation('name', 'ar'))->toBe('كرة القدم')
            ->and(Category::where('slug', 'tennis')->value('parent_id'))->toBe($sports->id)
            ->and(Category::where('slug', 'science')->value('parent_id'))->toBeNull()
            ->and($football->questions()->count())->toBe(2)->and($sports->questions()->count())->toBe(1);
    });

    it('numbers the top-level categories and each parent\'s subcategories from 1', function () {
        seedSmall();

        expect(Category::topLevel()->orderBy('sort_order')->pluck('slug')->all())->toBe(['sports', 'science'])
            ->and(Category::where('slug', 'football')->value('sort_order'))->toBe(1)->and(Category::where('slug', 'tennis')->value('sort_order'))->toBe(2);
    });

    it('puts a subcategory under a parent that already exists, whatever the admin called it', function () {
        $sports = Category::factory()->create(['slug' => 'sports', 'name' => ['ar' => 'ألعاب', 'en' => 'Games']]);
        $content = smallContent();
        unset($content['categories']['sports']);

        seedSmall($content);

        expect(Category::where('slug', 'football')->value('parent_id'))->toBe($sports->id)->and($sports->fresh()->getTranslation('name', 'en'))->toBe('Games');
    });

    it('stops without writing anything when a subcategory names a parent that does not exist', function () {
        $content = smallContent();
        $content['categories']['orphan'] = ['ar' => 'يتيم', 'en' => 'Orphan', 'icon' => 'x', 'parent' => 'nowhere'];

        expect(fn () => seedSmall($content))->toThrow(ModelNotFoundException::class);
        expect(Category::count())->toBe(0)->and(Question::count())->toBe(0);
    });
});

describe('the stable key', function () {
    it('is a hash of the English text, ignoring case and spacing', function () {
        expect(SeedKey::for('Who  Scores?'))->toBe(SeedKey::for(' who scores? '))->and(SeedKey::for('Who scores?'))->toHaveLength(40)
            ->and(SeedKey::for('Who scores?'))->not->toBe(SeedKey::for('Who scored?'));
    });

    it('is stored on every seeded question', function () {
        seedSmall();

        foreach (Question::all() as $question) {
            expect($question->seed_key)->toBe(SeedKey::for($question->getTranslation('text', 'en')));
        }
    });

    it('re-running adds only what is new and leaves everything else exactly as it was', function () {
        seedSmall();
        $before = Question::orderBy('id')->get(['id', 'seed_key', 'updated_at'])->toArray();

        seedSmall();
        expect(Question::orderBy('id')->get(['id', 'seed_key', 'updated_at'])->toArray())->toBe($before);

        $more = smallContent();
        $more['questions']['football'][] = ['hard', 'سؤال جديد', 'A brand new football question?', ['أ|A', 'ب|B', 'ج|C', 'د|D']];
        seedSmall($more);

        expect(Question::count())->toBe(count($before) + 1)->and(Question::orderBy('id')->limit(count($before))->get(['id', 'seed_key', 'updated_at'])->toArray())->toBe($before)
            ->and(Question::latest('id')->first()->category->slug)->toBe('football');
    });

    it('does not add a question again after an admin edited its English text', function () {
        seedSmall();
        $question = Question::where('seed_key', SeedKey::for('What is H2O?'))->firstOrFail();
        $question->update(['text' => ['ar' => 'ما هو الماء؟', 'en' => 'What is the chemical formula of water?']]);

        seedSmall();

        expect(Question::count())->toBe(5)->and($question->fresh()->getTranslation('text', 'en'))->toBe('What is the chemical formula of water?');
    });

    it('does not add a question again after an admin moved it to another category', function () {
        seedSmall();
        $question = Question::where('seed_key', SeedKey::for('What is a love score?'))->firstOrFail();
        $question->update(['category_id' => Category::where('slug', 'science')->value('id')]);

        seedSmall();

        expect(Question::count())->toBe(5)->and($question->fresh()->category->slug)->toBe('science');
    });

    it('leaves a removed question gone', function () {
        seedSmall();
        Question::where('seed_key', SeedKey::for('What is H2O?'))->firstOrFail()->delete();

        seedSmall();

        expect(Question::count())->toBe(4)->and(Question::withTrashed()->count())->toBe(5);
    });

    it('counts a question an admin wrote by hand as the shipped one with the same English text', function () {
        $science = Category::factory()->create(['slug' => 'science']);
        Question::factory()->withOptions()->create(['category_id' => $science->id, 'text' => ['ar' => 'ما هذا؟', 'en' => 'what is  h2o?'], 'seed_key' => null]);

        seedSmall();

        expect(Question::where('category_id', $science->id)->count())->toBe(1)->and(Question::count())->toBe(5);
    });
});

describe('giving the questions that already exist their key', function () {
    function backfill(): void
    {
        (require database_path('migrations/2026_10_26_110000_add_seed_key_to_questions.php'))->backfill();
    }

    it('fills it from the English text, and twice is harmless', function () {
        $a = Question::factory()->create(['text' => ['ar' => 'أ', 'en' => 'First?'], 'seed_key' => null]);
        $b = Question::factory()->create(['text' => ['ar' => 'ب'], 'seed_key' => null]);

        backfill();
        backfill();

        expect($a->fresh()->seed_key)->toBe(SeedKey::for('First?'))->and($b->fresh()->seed_key)->toBeNull();
    });

    it('gives a key to only one of two questions with the same English text', function () {
        $first = Question::factory()->create(['text' => ['ar' => 'أ', 'en' => 'Same?'], 'seed_key' => null]);
        $second = Question::factory()->create(['text' => ['ar' => 'ب', 'en' => 'same?'], 'seed_key' => null]);

        backfill();

        expect($first->fresh()->seed_key)->toBe(SeedKey::for('Same?'))->and($second->fresh()->seed_key)->toBeNull();
    });
});

describe('lamma:questions-report', function () {
    beforeEach(fn () => config(['lamma.content' => ['subcategory' => ['total' => 3, 'each' => 1], 'category' => ['total' => 4, 'each' => 1], 'warn_margin' => 1]]));

    function reportTree(int $football = 3, int $tennis = 3, int $science = 4): void
    {
        $sports = Category::factory()->create(['name' => ['en' => 'Sports', 'ar' => 'رياضة'], 'slug' => 'sports', 'sort_order' => 1]);
        $football = categoryWithQuestions($football, Difficulty::Easy, ['name' => ['en' => 'Football', 'ar' => 'كرة القدم'], 'slug' => 'football', 'parent_id' => $sports->id]);
        Question::factory()->withOptions()->create(['category_id' => $football->id, 'difficulty' => Difficulty::Medium]);
        Question::factory()->withOptions()->create(['category_id' => $football->id, 'difficulty' => Difficulty::Hard]);
        categoryWithQuestions($tennis, Difficulty::Hard, ['name' => ['en' => 'Tennis', 'ar' => 'التنس'], 'slug' => 'tennis', 'parent_id' => $sports->id]);
        categoryWithQuestions($science, Difficulty::Medium, ['name' => ['en' => 'Science', 'ar' => 'علوم'], 'slug' => 'science', 'sort_order' => 2]);
    }

    it('lists every category and subcategory with the counts per difficulty', function () {
        reportTree();

        $rows = collect(app(QuestionReport::class)->rows());

        expect($rows->map(fn ($row) => $row['category']->slug)->all())->toBe(['sports', 'football', 'tennis', 'science'])
            ->and($rows->firstWhere(fn ($row) => $row['category']->slug === 'football')['counts'])->toBe(['easy' => 3, 'medium' => 1, 'hard' => 1])
            ->and($rows->firstWhere(fn ($row) => $row['category']->slug === 'football')['total'])->toBe(5);
    });

    it('prints the table and exits with 0 when everything meets its target', function () {
        reportTree();
        Question::factory()->withOptions()->count(2)->create(['category_id' => Category::where('slug', 'science')->value('id'), 'difficulty' => Difficulty::Easy]);
        Question::factory()->withOptions()->create(['category_id' => Category::where('slug', 'science')->value('id'), 'difficulty' => Difficulty::Hard]);
        Question::factory()->withOptions()->create(['category_id' => Category::where('slug', 'tennis')->value('id'), 'difficulty' => Difficulty::Easy]);
        Question::factory()->withOptions()->create(['category_id' => Category::where('slug', 'tennis')->value('id'), 'difficulty' => Difficulty::Medium]);

        $this->artisan('lamma:questions-report')
            ->expectsOutputToContain('Football')->expectsOutputToContain('Tennis')->expectsOutputToContain('Sports (direct)')
            ->expectsOutputToContain('Every category meets its target.')
            ->assertSuccessful();
    });

    it('flags every cell under its target and exits with 1', function () {
        reportTree(football: 1, tennis: 3);   // tennis has hard only: no easy, no medium

        $this->artisan('lamma:questions-report')
            ->expectsOutputToContain('Sports > Tennis is under its target: easy, medium.')
            ->expectsOutputToContain('Science is under its target: easy, hard.')
            ->assertFailed();

        $rows = collect(app(QuestionReport::class)->rows())->keyBy(fn ($row) => $row['category']->slug);
        expect($rows['tennis']['short'])->toBe(['easy', 'medium'])->and($rows['football']['short'])->toBe([])->and($rows['science']['short'])->toBe(['easy', 'hard']);
    });

    it('flags a total under the target even when every difficulty has one', function () {
        reportTree(football: 1, tennis: 1);   // football: 1 easy + 1 medium + 1 hard = 3 (ok); tennis: 1 hard only

        $science = Category::where('slug', 'science')->value('id');
        Question::factory()->withOptions()->create(['category_id' => $science, 'difficulty' => Difficulty::Easy]);
        Question::factory()->withOptions()->create(['category_id' => $science, 'difficulty' => Difficulty::Hard]);
        config(['lamma.content.category' => ['total' => 9, 'each' => 1]]);

        $rows = collect(app(QuestionReport::class)->rows())->keyBy(fn ($row) => $row['category']->slug);
        expect($rows['science']['short'])->toBe(['total']);
    });

    it('gives a category that has subcategories no target of its own', function () {
        reportTree();

        $sports = collect(app(QuestionReport::class)->rows())->firstWhere(fn ($row) => $row['category']->slug === 'sports');

        expect($sports['target'])->toBeNull()->and($sports['short'])->toBe([]);
    });

    it('ignores inactive categories and questions that are not playable', function () {
        reportTree();
        Category::where('slug', 'science')->update(['is_active' => false]);
        Question::query()->where('category_id', Category::where('slug', 'football')->value('id'))->update(['is_active' => false]);
        Question::factory()->withOptions()->create(['category_id' => Category::where('slug', 'tennis')->value('id'), 'text' => ['en' => 'English only?']]);

        $rows = collect(app(QuestionReport::class)->rows())->keyBy(fn ($row) => $row['category']->slug);

        expect($rows)->not->toHaveKey('science')->and($rows['football']['total'])->toBe(0)->and($rows['tennis']['total'])->toBe(3);
    });
});

describe('rolling the subcategories migration back and forward', function () {
    it('puts the shipped subcategories back under their parents', function () {
        (new ContentSeeder)->run();
        Category::whereNotNull('parent_id')->update(['parent_id' => null]);      // what dropping the column leaves behind
        $admins = Category::factory()->create(['slug' => 'my-own-category']);     // a category of the admin's own is not touched

        (require database_path('migrations/2026_10_26_100000_add_parent_id_to_categories.php'))->restoreParents();

        expect(Category::whereNotNull('parent_id')->count())->toBe(13)
            ->and(Category::where('slug', 'football')->first()->parent->slug)->toBe('sports')
            ->and(Category::where('slug', 'human-body')->first()->parent->slug)->toBe('general-knowledge')
            ->and($admins->fresh()->parent_id)->toBeNull()
            ->and(Category::where('slug', 'sports')->first()->parent_id)->toBeNull();
    });
});
