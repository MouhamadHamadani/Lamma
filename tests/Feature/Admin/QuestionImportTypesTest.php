<?php

use App\Enums\QuestionType;
use App\Filament\Resources\Questions\Pages\ListQuestions;
use App\Game\QuestionImage;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Question;
use App\Support\QuestionImporter;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(Admin::factory()->create(), 'admin');
    $this->sports = Category::factory()->create(['name' => ['en' => 'Sports', 'ar' => 'رياضة'], 'slug' => 'sports']);
});

/** The header of a file with the new columns: the four every file has, the options and the six new ones. */
function typedHeader(): array
{
    return QuestionImporter::TEMPLATE;
}

/** A row under that header; the keys name the columns, the rest are empty. */
function typedRow(array $cells): array
{
    $row = array_replace(array_fill_keys(typedHeader(), ''), ['category' => 'Sports', 'difficulty' => 'easy', 'question_ar' => 'سؤال؟', 'question_en' => 'A question?'], $cells);

    return array_values($row);
}

function typedCsv(array $rows, ?array $header = null): string
{
    $handle = fopen('php://temp', 'r+');
    foreach ([$header ?? typedHeader(), ...$rows] as $row) {
        fputcsv($handle, $row, ',', '"', '');
    }
    rewind($handle);

    return stream_get_contents($handle);
}

/** Import this text; $pictures are the pictures uploaded with it (file name => bytes). */
function importTyped(string $content, array $pictures = []): array
{
    $dir = sys_get_temp_dir().'/lamma-import-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $paths = [];
    foreach ($pictures as $name => $bytes) {
        file_put_contents($paths[] = "{$dir}/{$name}", $bytes);
    }
    file_put_contents($csv = "{$dir}/questions.csv", $content);

    try {
        return app(QuestionImporter::class)->import($csv, $paths);
    } finally {
        array_map('unlink', [...$paths, $csv]);
        rmdir($dir);
    }
}

describe('the new columns', function () {
    it('needs only the four basic columns in a file that has a type column', function () {
        $result = importTyped(typedCsv([['Sports', 'easy', 'صحيح؟', 'True?', 'true_false', 'true']], ['category', 'difficulty', 'question_ar', 'question_en', 'type', 'answer']));

        expect($result)->toBe(['imported' => 1, 'skipped' => 0, 'errors' => []])
            ->and(Question::sole()->type)->toBe(QuestionType::TrueFalse);
    });

    it('still needs every column in a file without one, as before', function () {
        $result = importTyped(typedCsv([['Sports', 'easy', 'سؤال', 'Question?']], ['category', 'difficulty', 'question_ar', 'question_en']));

        expect($result['imported'])->toBe(0)->and($result['errors'][0])->toContain('must name these columns')->toContain('correct_ar');
    });

    it('reads a multiple-choice row as before, whether the type is empty or named', function (string $type) {
        $result = importTyped(typedCsv([typedRow(['correct_ar' => 'أ', 'correct_en' => 'A', 'wrong1_ar' => 'ب', 'wrong1_en' => 'B', 'wrong2_ar' => 'ج', 'wrong2_en' => 'C', 'wrong3_ar' => 'د', 'wrong3_en' => 'D', 'type' => $type])]));

        $question = Question::with('options')->sole();
        expect($result['imported'])->toBe(1)->and($question->type)->toBe(QuestionType::MultipleChoice)->and($question->options)->toHaveCount(4)->and($question->problems())->toBe([]);
    })->with(['', 'multiple_choice', 'Multiple choice', 'mc']);

    it('says what a multiple-choice row is missing when its options are not there', function () {
        $result = importTyped(typedCsv([typedRow(['type' => 'multiple_choice'])]));

        expect($result['imported'])->toBe(0)->and($result['errors'][0])->toContain('Row 2')->toContain('correct answer is empty');
    });

    it('rejects a type that is none of the three', function () {
        $result = importTyped(typedCsv([typedRow(['type' => 'essay'])]));

        expect($result['errors'][0])->toContain('multiple_choice, true_false or closest_number');
    });
});

describe('true/false rows', function () {
    it('takes the answer in several ways and makes True and False', function (string $answer, string $correct) {
        $result = importTyped(typedCsv([typedRow(['type' => 'true_false', 'answer' => $answer])]));

        $question = Question::with('options')->sole();
        expect($result['imported'])->toBe(1)->and($question->options->map(fn ($o) => $o->getTranslation('text', 'en'))->all())->toBe(['True', 'False'])
            ->and($question->options->firstWhere('is_correct', true)->getTranslation('text', 'en'))->toBe($correct)->and($question->problems())->toBe([]);
    })->with([
        ['true', 'True'], ['TRUE', 'True'], ['yes', 'True'], ['1', 'True'], ['صحيح', 'True'],
        ['false', 'False'], ['False', 'False'], ['no', 'False'], ['0', 'False'], ['خطأ', 'False'],
    ]);

    it('says what is wrong with an answer that is neither', function (string $answer) {
        $result = importTyped(typedCsv([typedRow(['type' => 'true_false', 'answer' => $answer])]));

        expect($result['imported'])->toBe(0)->and($result['errors'][0])->toContain('answer = true or false');
    })->with(['', 'maybe', '2']);
});

describe('closest-number rows', function () {
    it('takes the number as typed, Arabic digits and separators included, and fills in the tolerance', function (string $answer, float $number, float $tolerance) {
        $result = importTyped(typedCsv([typedRow(['type' => 'closest_number', 'answer' => $answer])]));

        $question = Question::with('options')->sole();
        expect($result['imported'])->toBe(1)->and($question->type)->toBe(QuestionType::ClosestNumber)->and($question->answer_number)->toBe($number)
            ->and($question->tolerance)->toBe($tolerance)->and($question->options)->toHaveCount(0)->and($question->problems())->toBe([]);
    })->with([
        ['1969', 1969.0, 492.25], ['١٩٦٩', 1969.0, 492.25], ['1,969', 1969.0, 492.25], ['150', 150.0, 37.5], ['2', 2.0, 1.0], ['3.5', 3.5, 1.0],
    ]);

    it('keeps a tolerance that was given', function () {
        importTyped(typedCsv([typedRow(['type' => 'closest_number', 'answer' => '1969', 'tolerance' => '10'])]));

        expect(Question::sole()->tolerance)->toBe(10.0);
    });

    it('says what is wrong with a number or a tolerance', function (string $answer, string $tolerance, string $message) {
        $result = importTyped(typedCsv([typedRow(['type' => 'closest_number', 'answer' => $answer, 'tolerance' => $tolerance])]));

        expect($result['imported'])->toBe(0)->and($result['errors'][0])->toContain($message);
    })->with([
        'no number' => ['', '', 'needs a number'],
        'letters' => ['abc', '', 'needs a number'],
        'a zero tolerance' => ['5', '0', 'above 0'],
        'a negative tolerance' => ['5', '-2', 'above 0'],
        'a tolerance that is text' => ['5', 'big', 'above 0'],
    ]);
});

describe('the fun fact', function () {
    it('is read in both languages, and either may be left out', function () {
        importTyped(typedCsv([
            typedRow(['type' => 'true_false', 'answer' => 'true', 'explanation_ar' => 'معلومة', 'explanation_en' => 'A fact', 'question_en' => 'First?']),
            typedRow(['type' => 'true_false', 'answer' => 'true', 'explanation_en' => 'Only English', 'question_en' => 'Second?']),
            typedRow(['type' => 'true_false', 'answer' => 'true', 'question_en' => 'Third?']),
        ]));

        $facts = Question::orderBy('id')->get()->map->getTranslations('explanation')->all();
        expect($facts)->toBe([['ar' => 'معلومة', 'en' => 'A fact'], ['en' => 'Only English'], []]);
    });

    it('stays short', function () {
        $result = importTyped(typedCsv([typedRow(['type' => 'true_false', 'answer' => 'true', 'explanation_en' => str_repeat('x', 301)])]));

        expect($result['imported'])->toBe(0)->and($result['errors'][0])->toContain('300 characters');
    });
});

describe('pictures', function () {
    it('are matched by file name, checked, re-saved as WebP and attached to the question, whatever the type', function (string $type, array $cells) {
        $result = importTyped(typedCsv([typedRow([...$cells, 'type' => $type, 'image' => 'Pyramids.JPG'])]), ['pyramids.jpg' => jpegBytes(640, 480)]);

        $question = Question::sole();
        expect($result)->toBe(['imported' => 1, 'skipped' => 0, 'errors' => []])->and($question->image_path)->toEndWith('.webp')->and($question->hasPicture())->toBeTrue()
            ->and(Storage::disk(QuestionImage::DISK)->exists($question->image_path))->toBeTrue();
    })->with([
        ['true_false', ['answer' => 'true']],
        ['closest_number', ['answer' => '5']],
        ['multiple_choice', ['correct_en' => 'A', 'correct_ar' => 'أ', 'wrong1_en' => 'B', 'wrong1_ar' => 'ب', 'wrong2_en' => 'C', 'wrong2_ar' => 'ج', 'wrong3_en' => 'D', 'wrong3_ar' => 'د']],
    ]);

    it('skips a row whose picture was not uploaded, and says which', function () {
        $result = importTyped(typedCsv([typedRow(['type' => 'true_false', 'answer' => 'true', 'image' => 'missing.jpg'])]), ['other.jpg' => jpegBytes()]);

        expect($result['imported'])->toBe(0)->and($result['errors'][0])->toContain('"missing.jpg" was not uploaded with the file');
    });

    it('skips a row whose picture is not a picture, and stores nothing', function (string $kind, string $message) {
        $bytes = match ($kind) {
            'text' => 'just text',
            'gif' => gifBytes(),
            'tiny' => jpegBytes(10, 10),
        };

        $result = importTyped(typedCsv([typedRow(['type' => 'true_false', 'answer' => 'true', 'image' => 'x.jpg'])]), ['x.jpg' => $bytes]);

        expect($result['imported'])->toBe(0)->and($result['errors'][0])->toContain('"x.jpg"')->toContain($message)
            ->and(Question::count())->toBe(0)->and(Storage::disk(QuestionImage::DISK)->allFiles())->toBe([]);
    })->with([['text', 'JPEG, PNG or WebP'], ['gif', 'JPEG, PNG or WebP'], ['tiny', 'too small']]);

    it('are not stored for a row that is wrong in another way', function () {
        Question::factory()->create(['text' => ['ar' => 'قديم', 'en' => 'Old one?']]);

        $result = importTyped(typedCsv([typedRow(['type' => 'true_false', 'answer' => 'true', 'question_en' => 'Old one?', 'image' => 'x.jpg'])]), ['x.jpg' => jpegBytes()]);

        expect($result['imported'])->toBe(0)->and(Storage::disk(QuestionImage::DISK)->allFiles())->toBe([]);
    });

    it('imports the good rows of a mixed file and reports the bad ones with their row numbers', function () {
        $result = importTyped(typedCsv([
            typedRow(['type' => 'true_false', 'answer' => 'true', 'question_en' => 'Good one?']),
            typedRow(['type' => 'true_false', 'answer' => 'perhaps', 'question_en' => 'Bad answer?']),
            typedRow(['type' => 'closest_number', 'answer' => '10', 'question_en' => 'Good number?']),
            typedRow(['type' => 'closest_number', 'answer' => 'ten', 'question_en' => 'Bad number?']),
        ]));

        expect($result['imported'])->toBe(2)->and($result['skipped'])->toBe(2)->and($result['errors'])->toHaveCount(2)
            ->and($result['errors'][0])->toContain('Row 3')->and($result['errors'][1])->toContain('Row 5');
    });
});

describe('the template', function () {
    it('has all the columns and one example of each type, and imports as a valid file', function () {
        $template = QuestionImporter::template();
        Category::factory()->create(['name' => ['en' => 'Football', 'ar' => 'كرة القدم'], 'slug' => 'football', 'parent_id' => $this->sports->id]);

        $result = importTyped($template);

        expect($template)->toStartWith("\xEF\xBB\xBF")->toContain('explanation_en')->toContain('closest_number')->and($result)->toBe(['imported' => 3, 'skipped' => 0, 'errors' => []])
            ->and(Question::orderBy('id')->get()->pluck('type')->map->value->all())->toBe(['multiple_choice', 'true_false', 'closest_number']);
        foreach (Question::with('options')->get() as $question) {
            expect($question->problems())->toBe([]);
        }
    });
});

describe('the import on the questions list', function () {
    it('takes the CSV and the pictures chosen with it, imports them and clears its temporary files', function () {
        Storage::fake('local');
        $csv = UploadedFile::fake()->createWithContent('questions.csv', typedCsv([typedRow(['type' => 'true_false', 'answer' => 'false', 'image' => 'hexagon.png'])]));
        $picture = UploadedFile::fake()->createWithContent('hexagon.png', pngBytes(400, 300));

        Livewire::test(ListQuestions::class)
            ->callAction('importQuestions', ['file' => $csv, 'pictures' => [$picture]])
            ->assertHasNoActionErrors()
            ->assertNotified('1 imported, 0 skipped');

        $question = Question::sole();
        expect($question->hasPicture())->toBeTrue()->and(Storage::disk(QuestionImage::DISK)->exists($question->image_path))->toBeTrue()
            ->and(Storage::disk('local')->allFiles())->toBe([]);
    });

    it('still takes a CSV with no pictures', function () {
        Storage::fake('local');
        $csv = UploadedFile::fake()->createWithContent('questions.csv', typedCsv([typedRow(['type' => 'closest_number', 'answer' => '42'])]));

        Livewire::test(ListQuestions::class)->callAction('importQuestions', ['file' => $csv])->assertHasNoActionErrors()->assertNotified('1 imported, 0 skipped');
    });
});
