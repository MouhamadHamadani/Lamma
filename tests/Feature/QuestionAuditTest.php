<?php

use App\Game\QuestionAudit;
use Database\Seeders\ContentSeeder;

/** A multiple-choice row: [difficulty, arabic, english, [correct, wrong, wrong, wrong]], options "arabic|english". */
function auditRow(string $en, array $options, string $difficulty = 'easy', array $extras = []): array
{
    return [$difficulty, 'سؤال', $en, $options, ...($extras ? [$extras] : [])];
}

/** Write a fixture bank to a file and return its path (same shape as ContentSeeder::data(), minus the categories). */
function auditFile(array $questions): string
{
    $path = tempnam(sys_get_temp_dir(), 'audit').'.php';
    file_put_contents($path, '<?php return '.var_export(['categories' => [], 'questions' => $questions], true).';');

    return $path;
}

describe('QuestionAudit', function () {
    it('counts the questions per slug and difficulty', function () {
        $audit = new QuestionAudit(['questions' => [
            'a' => [auditRow('One?', ['ب|B', 'ج|C', 'د|D', 'هـ|E'], 'easy'), auditRow('Two?', ['ب|B', 'ج|C', 'د|D', 'هـ|E'], 'hard'), auditRow('Three?', ['ب|B', 'ج|C', 'د|D', 'هـ|E'], 'hard')],
            'b' => [],
        ]]);

        expect($audit->counts())->toBe(['a' => ['easy' => 1, 'medium' => 0, 'hard' => 2, 'total' => 3], 'b' => ['easy' => 0, 'medium' => 0, 'hard' => 0, 'total' => 0]]);
    });

    it('counts a correct option as the longest only when it is strictly the longest, in each language', function () {
        $audit = new QuestionAudit(['questions' => ['a' => [
            auditRow('Strictly longest?', ['طويلة جداً|The longest answer', 'ب|Bb', 'ج|Cc', 'د|Dd']),
            auditRow('A tie is not a tell?', ['أأ|Aaaa', 'بب|Bbbb', 'ج|Cc', 'د|Dd']),
            auditRow('Shortest?', ['أ|A', 'بببب|Bbbbbb', 'جج|Cc', 'دد|Dd']),
            auditRow('Longest only in Arabic?', ['كلمة طويلة|Aa', 'ب|Bbbbbbb', 'ج|Cc', 'د|Dd']),
        ]]]);

        expect($audit->tells()['a'])->toMatchArray(['choice' => 4, 'longest_en' => 1, 'longest_ar' => 2, 'share_en' => 0.25, 'share_ar' => 0.5]);
    });

    it('does not read a true/false or closest-number question as multiple choice, and counts a closest number', function () {
        $audit = new QuestionAudit(['questions' => ['a' => [
            ['easy', 'صح؟', 'Is it so?', true, ['type' => 'true_false']],
            ['medium', 'كم؟', 'How many?', 12, ['type' => 'closest_number']],
            auditRow('One?', ['ب|B', 'ج|C', 'د|D', 'هـ|E']),
        ]]]);

        expect($audit->tells()['a'])->toMatchArray(['choice' => 1, 'closest' => 1]);
    });

    it('counts the correct answers that are a plain number', function () {
        $audit = new QuestionAudit(['questions' => ['a' => [
            auditRow('Six?', ['6', '5', '7', '8']), auditRow('Metres?', ['2.54', '3', '4', '5']), auditRow('Share?', ['21%', '20%', '30%', '10%']),
            auditRow('Words?', ['ستة|Six', '5', '7', '8']), auditRow('Unit?', ['2.44 متر|2.44 metres', '1', '2', '3']),
        ]]]);

        expect($audit->tells()['a']['numeric'])->toBe(3);
    });

    it('flags a correct answer that comes up more than three times in one slug, but not three, and not across slugs', function () {
        $cairo = fn (string $n) => auditRow("Which city {$n}?", ['القاهرة|Cairo', 'ب|B'.$n, 'ج|C'.$n, 'د|D'.$n]);
        $audit = new QuestionAudit(['questions' => [
            'four' => [$cairo('1'), $cairo('2'), $cairo('3'), $cairo('4')],
            'three' => [$cairo('1'), $cairo('2'), $cairo('3')],
            'spread' => [$cairo('1'), $cairo('2')],
            'spread-too' => [$cairo('3'), $cairo('4')],
        ]]);

        expect($audit->repeated())->toBe([['slug' => 'four', 'answer' => 'cairo', 'times' => 4]]);
    });

    it('flags different questions with the very same three wrong options, but not three plain numbers', function () {
        $audit = new QuestionAudit(['questions' => [
            'a' => [
                auditRow('Capital of France?', ['باريس|Paris', 'ليون|Lyon', 'نيس|Nice', 'مرسيليا|Marseille']),
                auditRow('Where is Roland Garros?', ['باريس|Paris', 'Nice|Nice', 'Lyon|Lyon', 'Marseille|marseille']),
                auditRow('How many sides?', ['5', '6', '7', '8']),
                auditRow('How many legs?', ['4', '6', '7', '8']),
            ],
        ]]);

        $identical = $audit->identical();
        expect($identical)->toHaveCount(1)->and($identical[0]['wrong'])->toBe(['lyon', 'marseille', 'nice'])
            ->and($identical[0]['questions'])->toBe(['a: Capital of France?', 'a: Where is Roland Garros?']);
    });

    it('finds time-sensitive words in the question and the fun fact, not in the options and not "electric current"', function () {
        $audit = new QuestionAudit(['questions' => ['a' => [
            auditRow('Who is the current champion?', ['ب|B', 'ج|C', 'د|D', 'هـ|E']),
            auditRow('Which is the latest model?', ['ب|B', 'ج|C', 'د|D', 'هـ|E']),
            auditRow('What happens today?', ['ب|B', 'ج|C', 'د|D', 'هـ|E']),
            auditRow('Who is the reigning king?', ['ب|B', 'ج|C', 'د|D', 'هـ|E']),
            auditRow('Who won in 2024?', ['ب|B', 'ج|C', 'د|D', 'هـ|E']),
            auditRow('What do Lebanese say for the present moment?', ['الآن|Now', 'ب|B', 'ج|C', 'د|D']),
            auditRow('What is the unit of electric current?', ['ب|Ampere', 'ج|C', 'د|D', 'هـ|E']),
            auditRow('Who won in 2022?', ['ب|B', 'ج|C', 'د|D', 'هـ|E']),
            ['easy', 'صح؟', 'Is it so?', true, ['type' => 'true_false', 'fact' => 'حقيقة|The record stands now.']],
        ]]]);

        expect(collect($audit->stale())->pluck('word')->all())->toBe(['current', 'latest', 'today', 'reigning', '2024', 'now'])
            ->and(collect($audit->stale())->pluck('question')->last())->toBe('Is it so?');
    });

    it('calls a clean bank clean', function () {
        $audit = new QuestionAudit(['questions' => ['a' => [
            auditRow('Capital of France?', ['باريس|Paris', 'ليون|Lyon', 'نيس|Nice', 'مرسيليا|Marseille']),
            auditRow('Capital of Italy?', ['روما|Rome', 'ميلانو|Milan', 'نابولي|Naples', 'البندقية|Venice']),
        ]]]);

        expect($audit->hasDefects())->toBeFalse()->and($audit->repeated())->toBe([])->and($audit->overLimit())->toBe([]);
    });

    it('flags a slug of at least 20 multiple-choice questions where the correct option is too often the longest', function () {
        $long = fn (int $n) => auditRow("Question {$n}?", ['إجابة طويلة جداً|A very long answer', 'أ|A'.$n, 'ب|B'.$n, 'ج|C'.$n]);
        $fair = fn (int $n) => auditRow("Fair {$n}?", ['أ|Aa', 'بببب|Bbbbbb', 'ج|Cc', 'د|Dd']);

        $audit = new QuestionAudit(['questions' => [
            'lazy' => [...array_map($long, range(1, 10)), ...array_map($fair, range(1, 10))],
            'fine' => [...array_map($long, range(1, 3)), ...array_map($fair, range(1, 17))],
            'small' => array_map($long, range(1, 10)),
        ]]);

        expect($audit->overLimit())->toBe(['lazy']);   // 50% of 20; 'fine' is 15%; 'small' has too few questions to say
    });

    it('audits the shipped bank: no time-sensitive wording beyond the two old questions that are already live, and no tell in the length of the answers', function () {
        $audit = new QuestionAudit(ContentSeeder::data());

        // Two questions from before Phase 15 say "today"; their English text is their identity in the database, so they cannot be reworded (a new wording would be a second question).
        expect(collect($audit->stale())->pluck('question')->sort()->values()->all())->toBe([
            'Which Chinese dynasty built most of the sections of the Great Wall that survive today?',
            'Which of the Seven Wonders of the Ancient World still stands today?',
        ]);

        $tells = collect($audit->tells());
        $choice = $tells->sum('choice');
        expect($tells->sum('longest_en') / $choice)->toBeLessThan(0.3)->and($tells->sum('longest_ar') / $choice)->toBeLessThan(0.3);
    });
});

describe('lamma:questions-audit', function () {
    afterEach(fn () => array_map('unlink', glob(sys_get_temp_dir().'/audit*.php') ?: []));

    it('prints the counts and exits 0 on a clean file, even with --strict', function () {
        $file = auditFile(['a' => [auditRow('Capital of France?', ['باريس|Paris', 'ليون|Lyon', 'نيس|Nice', 'مرسيليا|Marseille'])]]);

        $this->artisan('lamma:questions-audit', ['--file' => $file])->expectsOutputToContain('1 questions, 1 multiple-choice')->expectsOutputToContain('No defects.')->assertSuccessful();
        $this->artisan('lamma:questions-audit', ['--file' => $file, '--strict' => true])->assertSuccessful();
    });

    it('prints every kind of problem a fixture has', function () {
        $dull = fn (string $n) => auditRow("Which city {$n}?", ['القاهرة|Cairo', 'ب|B'.$n, 'ج|C'.$n, 'د|D'.$n]);
        $file = auditFile(['a' => [
            $dull('1'), $dull('2'), $dull('3'), $dull('4'),
            auditRow('Capital of France?', ['باريس|Paris', 'ليون|Lyon', 'نيس|Nice', 'مرسيليا|Marseille']),
            auditRow('Where is Roland Garros?', ['باريس|Paris', 'Lyon|Lyon', 'Nice|Nice', 'Marseille|Marseille']),
            auditRow('Who is the current champion?', ['ب|X', 'ج|Y', 'د|Z', 'هـ|W']),
        ]]);

        $this->artisan('lamma:questions-audit', ['--file' => $file])
            ->expectsOutputToContain('"cairo" is the correct answer 4 times')
            ->expectsOutputToContain('Same three wrong options (lyon, marseille, nice) in: a: Capital of France? / a: Where is Roland Garros?')
            ->expectsOutputToContain('time-sensitive word "current"')
            ->expectsOutputToContain('Defects found')
            ->assertSuccessful();
    });

    it('exits 1 with --strict when there is a defect', function () {
        $file = auditFile(['a' => [auditRow('What is the latest model?', ['ب|B', 'ج|C', 'د|D', 'هـ|E'])]]);

        $this->artisan('lamma:questions-audit', ['--file' => $file, '--strict' => true])->expectsOutputToContain('time-sensitive word "latest"')->assertFailed();
    });

    it('says so when the file is missing or is not a bank', function () {
        $this->artisan('lamma:questions-audit', ['--file' => '/no/such/file.php'])->expectsOutputToContain('No such file')->assertFailed();

        $notABank = tempnam(sys_get_temp_dir(), 'audit').'.php';
        file_put_contents($notABank, '<?php return ["hello" => 1];');
        $this->artisan('lamma:questions-audit', ['--file' => $notABank])->expectsOutputToContain("must return an array with a 'questions' key")->assertFailed();
    });

    it('audits the shipped bank by default, listing every slug', function () {
        $this->artisan('lamma:questions-audit')->expectsOutputToContain('lebanon-levant')->expectsOutputToContain('football')->expectsOutputToContain('Warnings')->assertSuccessful();
    });
});
