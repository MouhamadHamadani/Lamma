<?php

use App\Enums\Difficulty;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Categories\RelationManagers\SubcategoriesRelationManager;
use App\Filament\Resources\Questions\Pages\CreateQuestion;
use App\Filament\Resources\Questions\Pages\EditQuestion;
use App\Filament\Resources\Questions\Pages\ListQuestions;
use App\Filament\Widgets\MostPlayedCategoriesChart;
use App\Game\GameStats;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Question;
use App\Models\Room;
use App\Models\RoomQuestion;
use App\Support\CategoryOptions;
use App\Support\QuestionImporter;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(Admin::factory()->create(), 'admin');

    $this->sports = Category::factory()->create(['name' => ['en' => 'Sports', 'ar' => 'رياضة'], 'slug' => 'sports', 'sort_order' => 1]);
    $this->football = Category::factory()->under($this->sports)->create(['name' => ['en' => 'Football', 'ar' => 'كرة القدم'], 'slug' => 'football', 'sort_order' => 1]);
    $this->tennis = Category::factory()->under($this->sports)->create(['name' => ['en' => 'Tennis', 'ar' => 'التنس'], 'slug' => 'tennis', 'sort_order' => 2]);
    $this->science = Category::factory()->create(['name' => ['en' => 'Science', 'ar' => 'علوم'], 'slug' => 'science', 'sort_order' => 2]);
});

describe('the category form', function () {
    it('offers only top-level categories as a parent, never the category itself', function () {
        $page = Livewire::test(EditCategory::class, ['record' => $this->science->getRouteKey()]);

        $options = $page->instance()->getSchema('form')->getFlatFields(withHidden: true)['parent_id']->getOptions();

        expect($options)->toHaveKeys([$this->sports->id])->not->toHaveKey($this->football->id)->not->toHaveKey($this->science->id);
    });

    it('creates a subcategory under a top-level category', function () {
        Livewire::test(CreateCategory::class)
            ->fillForm(['name' => ['ar' => 'الرغبي', 'en' => 'Rugby'], 'slug' => 'rugby', 'parent_id' => $this->sports->id, 'is_active' => true, 'sort_order' => 3])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Category::where('slug', 'rugby')->first()->parent_id)->toBe($this->sports->id);
    });

    it('refuses a parent that is itself a subcategory', function () {
        Livewire::test(CreateCategory::class)
            ->fillForm(['name' => ['ar' => 'ب', 'en' => 'Deep'], 'slug' => 'deep', 'parent_id' => $this->football->id, 'is_active' => true, 'sort_order' => 1])
            ->call('create')
            ->assertHasFormErrors(['parent_id']);

        expect(Category::where('slug', 'deep')->exists())->toBeFalse();
    });

    it('locks the parent of a category that has subcategories', function () {
        Livewire::test(EditCategory::class, ['record' => $this->sports->getRouteKey()])->assertFormFieldIsDisabled('parent_id');
        Livewire::test(EditCategory::class, ['record' => $this->science->getRouteKey()])->assertFormFieldEnabled('parent_id');
    });

    it('moves a childless category under a parent and back to the top', function () {
        Livewire::test(EditCategory::class, ['record' => $this->science->getRouteKey()])
            ->fillForm(['parent_id' => $this->sports->id])->call('save')->assertHasNoFormErrors();
        expect($this->science->fresh()->parent_id)->toBe($this->sports->id);

        Livewire::test(EditCategory::class, ['record' => $this->science->getRouteKey()])
            ->fillForm(['parent_id' => null])->call('save')->assertHasNoFormErrors();
        expect($this->science->fresh()->parent_id)->toBeNull();
    });

    it('has no way to delete a parent that still has subcategories', function () {
        Livewire::test(EditCategory::class, ['record' => $this->sports->getRouteKey()])->assertActionHidden('delete');
        Livewire::test(EditCategory::class, ['record' => $this->science->getRouteKey()])->assertActionVisible('delete');
    });
});

describe('the Subcategories tab', function () {
    it('is on a top-level category and not on a subcategory', function () {
        expect(SubcategoriesRelationManager::canViewForRecord($this->sports, EditCategory::class))->toBeTrue()
            ->and(SubcategoriesRelationManager::canViewForRecord($this->football, EditCategory::class))->toBeFalse();
    });

    it('lists the subcategories of that category only', function () {
        $other = Category::factory()->under($this->science)->create(['slug' => 'physics']);

        Livewire::test(SubcategoriesRelationManager::class, ['ownerRecord' => $this->sports, 'pageClass' => EditCategory::class])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$this->football, $this->tennis])
            ->assertCanNotSeeTableRecords([$other, $this->science]);
    });

    it('shows on the parent\'s edit page', function () {
        $this->get(EditCategory::getUrl(['record' => $this->sports]))->assertOk()->assertSee('Subcategories');
    });
});

describe('the category list', function () {
    it('shows each subcategory under its parent, with a parent column and the number of subcategories', function () {
        $page = Livewire::test(ListCategories::class)->assertSuccessful();

        $page->assertCanSeeTableRecords([$this->sports, $this->football, $this->tennis, $this->science], inOrder: false)
            ->assertTableColumnStateSet('parent.name', 'Sports', $this->football)
            ->assertTableColumnStateSet('children_count', 2, $this->sports);
        expect($page->instance()->getTableRecords()->pluck('slug')->all())->toBe(['sports', 'football', 'tennis', 'science']);
    });

    it('filters by parent and to the top-level ones', function () {
        Livewire::test(ListCategories::class)
            ->filterTable('parent_id', $this->sports->id)
            ->assertCanSeeTableRecords([$this->football, $this->tennis])->assertCanNotSeeTableRecords([$this->sports, $this->science])
            ->resetTableFilters()
            ->filterTable('top_level')
            ->assertCanSeeTableRecords([$this->sports, $this->science])->assertCanNotSeeTableRecords([$this->football, $this->tennis]);
    });
});

describe('the question form and list', function () {
    function questionIn(Category $category, array $attributes = []): Question
    {
        return Question::factory()->withOptions()->create(['category_id' => $category->id, 'difficulty' => Difficulty::Easy, ...$attributes]);
    }

    it('groups the category select by parent', function () {
        expect(CategoryOptions::grouped())->toBe([
            'Categories' => [$this->science->id => 'Science'],
            'Sports' => [$this->sports->id => 'Sports (general)', $this->football->id => 'Football', $this->tennis->id => 'Tennis'],
        ]);
    });

    it('files a question under a subcategory', function () {
        Livewire::test(CreateQuestion::class)
            ->fillForm([
                'category_id' => $this->football->id, 'difficulty' => Difficulty::Easy, 'text' => ['ar' => 'ما هذا؟', 'en' => 'What is this?'], 'is_active' => true,
                'options' => collect(range(1, 4))->map(fn ($i) => ['text' => ['ar' => "خيار {$i}", 'en' => "Option {$i}"], 'is_correct' => $i === 1])->all(),
            ])
            ->call('create')->assertHasNoFormErrors();

        expect(Question::sole()->category_id)->toBe($this->football->id);
    });

    it('shows "Sports · Football" in the list and filters by category and by subcategory', function () {
        $direct = questionIn($this->sports);
        $kick = questionIn($this->football);
        $serve = questionIn($this->tennis);
        $atom = questionIn($this->science);

        Livewire::test(ListQuestions::class)
            ->assertSuccessful()
            ->assertTableColumnStateSet('category.name', 'Sports · Football', $kick)
            ->assertTableColumnStateSet('category.name', 'Science', $atom)
            ->filterTable('category', $this->sports->id)
            ->assertCanSeeTableRecords([$direct, $kick, $serve])->assertCanNotSeeTableRecords([$atom])
            ->resetTableFilters()
            ->filterTable('subcategory', $this->football->id)
            ->assertCanSeeTableRecords([$kick])->assertCanNotSeeTableRecords([$direct, $serve, $atom]);
    });

    it('keeps the "missing translation" filter', function () {
        $complete = questionIn($this->football);
        $missing = questionIn($this->football, ['text' => ['en' => 'English only?']]);

        Livewire::test(ListQuestions::class)->filterTable('missing_translation')->assertCanSeeTableRecords([$missing])->assertCanNotSeeTableRecords([$complete]);
    });

    it('edits a question under a subcategory', function () {
        $question = questionIn($this->tennis);

        Livewire::test(EditQuestion::class, ['record' => $question->getRouteKey()])->assertSuccessful()->assertFormSet(['category_id' => $this->tennis->id]);
    });
});

describe('importing questions from a CSV', function () {
    /** A CSV text from a header and rows (each a list of cells). */
    function csv(array $rows, string $delimiter = ',', ?array $header = null): string
    {
        $handle = fopen('php://temp', 'r+');
        foreach ([$header ?? QuestionImporter::REQUIRED, ...$rows] as $row) {
            fputcsv($handle, $row, $delimiter, '"', '');
        }
        rewind($handle);

        return stream_get_contents($handle);
    }

    function csvRow(string $category, string $en = 'Who won?', string $difficulty = 'easy', array $override = []): array
    {
        return array_replace([$category, $difficulty, 'من فاز؟', $en, 'أ', 'A', 'ب', 'B', 'ج', 'C', 'د', 'D'], $override);
    }

    function importCsv(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'lamma-import');
        file_put_contents($path, $content);

        try {
            return app(QuestionImporter::class)->import($path);
        } finally {
            unlink($path);
        }
    }

    it('files a row under "Parent > Child", by name, slug or the Arabic name, in any letter case', function (string $category) {
        $result = importCsv(csv([csvRow($category)]));

        expect($result)->toBe(['imported' => 1, 'skipped' => 0, 'errors' => []])
            ->and(Question::sole()->category_id)->toBe($this->football->id);
    })->with(['Sports > Football', 'sports > football', 'SPORTS>FOOTBALL', 'رياضة > كرة القدم', 'sports > كرة القدم', ' Sports  >  Football ', 'football']);

    it('files a row under a top-level category', function () {
        importCsv(csv([csvRow('Sports'), csvRow('science', 'Another?')]));

        expect(Question::orderBy('id')->pluck('category_id')->all())->toBe([$this->sports->id, $this->science->id]);
    });

    it('creates the options with exactly one correct, in both languages', function () {
        importCsv(csv([csvRow('Sports > Tennis')]));

        $question = Question::with('options')->sole();
        expect($question->getTranslations('text'))->toBe(['ar' => 'من فاز؟', 'en' => 'Who won?'])
            ->and($question->difficulty)->toBe(Difficulty::Easy)->and($question->is_active)->toBeTrue()
            ->and($question->options)->toHaveCount(4)->and($question->options->where('is_correct', true))->toHaveCount(1)
            ->and($question->options->firstWhere('is_correct', true)->getTranslations('text'))->toBe(['ar' => 'أ', 'en' => 'A'])
            ->and($question->isTranslatedIn(['ar', 'en']))->toBeTrue();
    });

    it('reports a row with an unknown category, parent or child, and imports the rest', function () {
        $result = importCsv(csv([
            csvRow('Sports > Football', 'Good one?'),
            csvRow('Sports > Curling', 'Unknown child?'),
            csvRow('Cooking > Football', 'Unknown parent?'),
            csvRow('Nowhere', 'Unknown category?'),
            csvRow('', 'No category?'),
            csvRow('A > B > C', 'Too deep?'),
            csvRow('Science > Football', 'Wrong parent?'),
        ]));

        expect($result['imported'])->toBe(1)->and($result['skipped'])->toBe(6)->and($result['errors'])->toHaveCount(6)
            ->and($result['errors'][0])->toContain('Row 3')->toContain('"Sports" has no subcategory "Curling"')
            ->and($result['errors'][1])->toContain('Row 4')->toContain('Unknown category "Cooking"')
            ->and($result['errors'][2])->toContain('Row 5')->toContain('Unknown category "Nowhere"')
            ->and($result['errors'][3])->toContain('Row 6')->toContain('category is empty')
            ->and($result['errors'][4])->toContain('Row 7')->toContain('must be a category')
            ->and($result['errors'][5])->toContain('Row 8')->toContain('"Science" has no subcategory "Football"');
        expect(Question::count())->toBe(1);
    });

    it('does not guess when a bare name fits two categories', function () {
        $other = Category::factory()->under($this->science)->create(['name' => ['en' => 'Football', 'ar' => 'كرة'], 'slug' => 'science-football']);

        $result = importCsv(csv([csvRow('Football')]));

        expect($result['imported'])->toBe(0)->and($result['errors'][0])->toContain('matches more than one category')->toContain('Sports · Football')->toContain('Science · Football');
        expect(importCsv(csv([csvRow('Science > Football', 'Second?')]))['imported'])->toBe(1);
    });

    it('reports a bad difficulty, a missing text and the wrong number of options', function () {
        $result = importCsv(csv([
            csvRow('Sports', 'Bad difficulty?', 'impossible'),
            csvRow('Sports', '', 'easy', [2 => '', 3 => '']),
            csvRow('Sports', 'Three options?', 'easy', [10 => '', 11 => '']),
            csvRow('Sports', 'No correct?', 'easy', [4 => '', 5 => '']),
        ]));

        expect($result['imported'])->toBe(0)
            ->and($result['errors'][0])->toContain('easy, medium or hard')
            ->and($result['errors'][1])->toContain('needs a text')
            ->and($result['errors'][2])->toContain('4 or 5 options')
            ->and($result['errors'][3])->toContain('correct answer is empty');
    });

    it('skips a question that already exists, or repeats in the file', function () {
        questionIn($this->football, ['text' => ['ar' => 'قديم', 'en' => 'Old one?']]);

        $result = importCsv(csv([csvRow('Sports', 'old  ONE?'), csvRow('Sports', 'New?'), csvRow('Sports', 'new?')]));

        expect($result['imported'])->toBe(1)->and($result['skipped'])->toBe(2)->and($result['errors'][0])->toContain('already exists');
    });

    it('accepts a semicolon-separated file, a byte-order mark, CRLF lines and a fifth option', function () {
        $header = [...QuestionImporter::REQUIRED, 'wrong4_ar', 'wrong4_en', 'active'];
        $content = "\xEF\xBB\xBF".str_replace("\n", "\r\n", csv([csvRow('Sports > Football', 'Five options?', 'Hard', [12 => 'هـ', 13 => 'E', 14 => 'no'])], ';', $header));

        $result = importCsv($content);

        $question = Question::with('options')->sole();
        expect($result['imported'])->toBe(1)->and($question->options)->toHaveCount(5)->and($question->is_active)->toBeFalse()->and($question->difficulty)->toBe(Difficulty::Hard);
    });

    it('stops with a clear message when a column is missing, and imports nothing', function () {
        $result = importCsv(csv([['Sports', 'easy', 'سؤال', 'Question?']], header: ['category', 'difficulty', 'question_ar', 'question_en']));

        expect($result['imported'])->toBe(0)->and($result['errors'][0])->toContain('must name these columns')->toContain('correct_ar');
        expect(Question::count())->toBe(0);
        expect(importCsv('')['errors'][0])->toContain('must name these columns');
    });

    it('ignores empty lines and gives the template back as a valid file', function () {
        $result = importCsv(csv([csvRow('Sports'), [''], csvRow('Science', 'Other?')]));
        expect($result['imported'])->toBe(2);

        Question::query()->delete();
        $template = QuestionImporter::template();
        expect($template)->toStartWith("\xEF\xBB\xBF")->and(importCsv($template)['imported'])->toBe(1);
    });

    it('imports an uploaded file from the questions list and says how it went', function () {
        Storage::fake('local');
        $file = UploadedFile::fake()->createWithContent('questions.csv', csv([csvRow('Sports > Football', 'Uploaded?'), csvRow('Sports > Curling', 'Broken?')]));

        Livewire::test(ListQuestions::class)
            ->callAction('importQuestions', ['file' => $file])
            ->assertHasNoActionErrors()
            ->assertNotified('1 imported, 1 skipped');

        expect(Question::sole()->getTranslation('text', 'en'))->toBe('Uploaded?');
    });
});

describe('the most-played categories widget', function () {
    function asked(Question $question): void
    {
        RoomQuestion::factory()->for(Room::factory()->finished())->create(['question_id' => $question->id, 'started_at' => now()]);
    }

    it('rolls subcategories up into their parent, with a second view for the subcategories', function () {
        foreach ([[$this->sports, 1], [$this->football, 3], [$this->tennis, 1], [$this->science, 4]] as [$category, $times]) {
            for ($i = 0; $i < $times; $i++) {
                asked(Question::factory()->withOptions()->create(['category_id' => $category->id]));
            }
        }

        $rolled = app(GameStats::class)->mostPlayedCategories();
        expect($rolled->pluck('room_questions_count', 'slug')->all())->toBe(['sports' => 5, 'science' => 4]);

        $subs = app(GameStats::class)->mostPlayedCategories(rollUp: false);
        expect($subs->pluck('room_questions_count', 'slug')->all())->toBe(['football' => 3, 'tennis' => 1]);

        $chart = fn (string $filter) => Livewire::test(MostPlayedCategoriesChart::class)->set('filter', $filter)->instance();
        $data = (new ReflectionMethod(MostPlayedCategoriesChart::class, 'getData'));
        expect($data->invoke($chart('categories'))['labels'])->toBe(['Sports', 'Science'])
            ->and($data->invoke($chart('subcategories'))['labels'])->toBe(['Sports · Football', 'Sports · Tennis']);
    });

    it('does not count unstarted or deleted questions', function () {
        $deleted = Question::factory()->withOptions()->create(['category_id' => $this->football->id]);
        asked($deleted);
        RoomQuestion::factory()->for(Room::factory()->finished())->create(['question_id' => Question::factory()->withOptions()->create(['category_id' => $this->tennis->id])->id, 'started_at' => null]);
        $deleted->delete();

        expect(app(GameStats::class)->mostPlayedCategories())->toHaveCount(0);
    });
});

describe('the admin pages', function () {
    it('render', function (string $url) {
        $this->get($url)->assertOk();
    })->with([
        'categories' => fn () => ListCategories::getUrl(),
        'create category' => fn () => CreateCategory::getUrl(),
        'questions' => fn () => ListQuestions::getUrl(),
        'create question' => fn () => CreateQuestion::getUrl(),
        'dashboard' => fn () => Filament::getUrl(),
    ]);
});
