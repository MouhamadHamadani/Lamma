<?php

namespace Database\Seeders;

use App\Enums\Difficulty;
use App\Models\Category;
use Illuminate\Database\Seeder;

class QuestionSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->questions() as $slug => $questions) {
            $category = Category::where('slug', $slug)->firstOrFail();

            if ($category->questions()->exists()) {
                continue; // already seeded
            }

            foreach ($questions as $index => [$difficulty, $ar, $en, $options]) {
                $question = $category->questions()->create([
                    'text' => ['ar' => $ar, 'en' => $en],
                    'difficulty' => $difficulty,
                    'is_active' => true,
                ]);

                // $options lists the correct answer first; spread it across positions 1-4.
                $correct = array_shift($options);
                array_splice($options, ($index * 3 + 1) % 4, 0, [$correct]);

                foreach ($options as $position => [$optionAr, $optionEn]) {
                    $question->options()->create([
                        'text' => ['ar' => $optionAr, 'en' => $optionEn],
                        'is_correct' => [$optionAr, $optionEn] === $correct,
                        'sort_order' => $position + 1,
                    ]);
                }
            }
        }
    }

    /**
     * category slug => [[difficulty, arabic, english, [correct option, wrong, wrong, wrong]], ...]
     * Every option is [arabic, english].
     *
     * @return array<string, list<array{0: Difficulty, 1: string, 2: string, 3: list<array{0: string, 1: string}>}>>
     */
    private function questions(): array
    {
        $easy = Difficulty::Easy;
        $medium = Difficulty::Medium;
        $hard = Difficulty::Hard;

        return [
            'general-knowledge' => [
                [$easy, 'ما هو أكبر حيوان ثديي في العالم؟', 'What is the largest mammal in the world?', [
                    ['الحوت الأزرق', 'Blue whale'], ['الفيل الأفريقي', 'African elephant'], ['الزرافة', 'Giraffe'], ['الدب القطبي', 'Polar bear'],
                ]],
                [$easy, 'كم عدد الأيام في السنة الكبيسة؟', 'How many days are there in a leap year?', [
                    ['366', '366'], ['365', '365'], ['364', '364'], ['367', '367'],
                ]],
                [$easy, 'ما اللون الناتج عن مزج الأزرق والأصفر؟', 'Which colour do you get by mixing blue and yellow?', [
                    ['الأخضر', 'Green'], ['البنفسجي', 'Purple'], ['البرتقالي', 'Orange'], ['البني', 'Brown'],
                ]],
                [$easy, 'كم عدد اللاعبين من الفريق الواحد داخل الملعب في مباراة كرة القدم؟', 'How many players from one team are on the pitch in a football match?', [
                    ['11', '11'], ['9', '9'], ['10', '10'], ['12', '12'],
                ]],
                [$easy, 'كم عدد مفاتيح البيانو القياسي؟', 'How many keys does a standard piano have?', [
                    ['88', '88'], ['76', '76'], ['64', '64'], ['100', '100'],
                ]],
                [$medium, 'من رسم لوحة الموناليزا؟', 'Who painted the Mona Lisa?', [
                    ['ليوناردو دافنشي', 'Leonardo da Vinci'], ['مايكل أنجلو', 'Michelangelo'], ['رافاييل', 'Raphael'], ['فنسنت فان غوخ', 'Vincent van Gogh'],
                ]],
                [$medium, 'في أي رياضة يُستخدم مصطلح «السلام دانك»؟', 'In which sport is the term "slam dunk" used?', [
                    ['كرة السلة', 'Basketball'], ['الكرة الطائرة', 'Volleyball'], ['كرة اليد', 'Handball'], ['التنس', 'Tennis'],
                ]],
                [$medium, 'من كتب مسرحية «روميو وجولييت»؟', 'Who wrote the play "Romeo and Juliet"?', [
                    ['ويليام شكسبير', 'William Shakespeare'], ['تشارلز ديكنز', 'Charles Dickens'], ['مارك توين', 'Mark Twain'], ['جين أوستن', 'Jane Austen'],
                ]],
                [$hard, 'أي لغة لديها أكبر عدد من المتحدثين الأصليين في العالم؟', 'Which language has the most native speakers in the world?', [
                    ['الصينية (الماندرين)', 'Mandarin Chinese'], ['الإنجليزية', 'English'], ['الإسبانية', 'Spanish'], ['الهندية', 'Hindi'],
                ]],
                [$hard, 'أي من عجائب الدنيا السبع القديمة ما زالت قائمة حتى اليوم؟', 'Which of the Seven Wonders of the Ancient World still stands today?', [
                    ['الهرم الأكبر في الجيزة', 'Great Pyramid of Giza'], ['تمثال رودس العملاق', 'Colossus of Rhodes'], ['حدائق بابل المعلقة', 'Hanging Gardens of Babylon'], ['فنار الإسكندرية', 'Lighthouse of Alexandria'],
                ]],
            ],

            'science' => [
                [$easy, 'ما الصيغة الكيميائية للماء؟', 'What is the chemical formula of water?', [
                    ['H2O', 'H2O'], ['CO2', 'CO2'], ['O2', 'O2'], ['NaCl', 'NaCl'],
                ]],
                [$easy, 'أي كوكب يُعرف بالكوكب الأحمر؟', 'Which planet is known as the Red Planet?', [
                    ['المريخ', 'Mars'], ['الزهرة', 'Venus'], ['المشتري', 'Jupiter'], ['عطارد', 'Mercury'],
                ]],
                [$easy, 'كم عدد أرجل الحشرة؟', 'How many legs does an insect have?', [
                    ['6', '6'], ['4', '4'], ['8', '8'], ['10', '10'],
                ]],
                [$easy, 'ما الغاز الذي تمتصه النباتات من الهواء لعملية البناء الضوئي؟', 'Which gas do plants absorb from the air for photosynthesis?', [
                    ['ثاني أكسيد الكربون', 'Carbon dioxide'], ['الأكسجين', 'Oxygen'], ['النيتروجين', 'Nitrogen'], ['الهيدروجين', 'Hydrogen'],
                ]],
                [$medium, 'ما أصلب مادة طبيعية على الأرض؟', 'What is the hardest natural substance on Earth?', [
                    ['الألماس', 'Diamond'], ['الذهب', 'Gold'], ['الحديد', 'Iron'], ['الكوارتز', 'Quartz'],
                ]],
                [$medium, 'ما الرمز الكيميائي للذهب؟', 'What is the chemical symbol for gold?', [
                    ['Au', 'Au'], ['Ag', 'Ag'], ['Fe', 'Fe'], ['Cu', 'Cu'],
                ]],
                [$medium, 'كم عدد العظام في جسم الإنسان البالغ؟', 'How many bones are in the adult human body?', [
                    ['206', '206'], ['186', '186'], ['226', '226'], ['306', '306'],
                ]],
                [$medium, 'أي جزء من الخلية يُعرف بـ«محطة الطاقة»؟', 'Which part of the cell is known as its "powerhouse"?', [
                    ['الميتوكوندريا', 'Mitochondrion'], ['النواة', 'Nucleus'], ['الريبوسومات', 'Ribosomes'], ['جهاز غولجي', 'Golgi apparatus'],
                ]],
                [$hard, 'ما السرعة التقريبية للضوء في الفراغ؟', 'What is the approximate speed of light in a vacuum?', [
                    ['300,000 كم/ث', '300,000 km/s'], ['150,000 كم/ث', '150,000 km/s'], ['30,000 كم/ث', '30,000 km/s'], ['3,000,000 كم/ث', '3,000,000 km/s'],
                ]],
                [$hard, 'ما الغاز الذي يشكّل معظم الغلاف الجوي للأرض؟', "Which gas makes up most of Earth's atmosphere?", [
                    ['النيتروجين', 'Nitrogen'], ['الأكسجين', 'Oxygen'], ['ثاني أكسيد الكربون', 'Carbon dioxide'], ['الأرجون', 'Argon'],
                ]],
            ],

            'geography' => [
                [$easy, 'ما عاصمة فرنسا؟', 'What is the capital of France?', [
                    ['باريس', 'Paris'], ['ليون', 'Lyon'], ['مرسيليا', 'Marseille'], ['نيس', 'Nice'],
                ]],
                [$easy, 'ما أكبر محيط على وجه الأرض؟', 'Which is the largest ocean on Earth?', [
                    ['المحيط الهادئ', 'Pacific Ocean'], ['المحيط الأطلسي', 'Atlantic Ocean'], ['المحيط الهندي', 'Indian Ocean'], ['المحيط المتجمد الشمالي', 'Arctic Ocean'],
                ]],
                [$easy, 'في أي قارة تقع الصحراء الكبرى؟', 'On which continent is the Sahara Desert?', [
                    ['أفريقيا', 'Africa'], ['آسيا', 'Asia'], ['أستراليا', 'Australia'], ['أمريكا الجنوبية', 'South America'],
                ]],
                [$easy, 'ما عاصمة اليابان؟', 'What is the capital of Japan?', [
                    ['طوكيو', 'Tokyo'], ['أوساكا', 'Osaka'], ['كيوتو', 'Kyoto'], ['سيول', 'Seoul'],
                ]],
                [$medium, 'ما عاصمة أستراليا؟', 'What is the capital of Australia?', [
                    ['كانبرا', 'Canberra'], ['سيدني', 'Sydney'], ['ملبورن', 'Melbourne'], ['بيرث', 'Perth'],
                ]],
                [$medium, 'أي دولة هي الأكبر في العالم من حيث المساحة؟', 'Which country has the largest land area in the world?', [
                    ['روسيا', 'Russia'], ['كندا', 'Canada'], ['الصين', 'China'], ['الولايات المتحدة', 'United States'],
                ]],
                [$medium, 'إلى أي سلسلة جبلية ينتمي جبل إفرست؟', 'Mount Everest belongs to which mountain range?', [
                    ['جبال الهيمالايا', 'Himalayas'], ['جبال الأنديز', 'Andes'], ['جبال الألب', 'Alps'], ['جبال روكي', 'Rocky Mountains'],
                ]],
                [$medium, 'أي خط وهمي يقسم الأرض إلى نصفين شمالي وجنوبي؟', 'Which imaginary line divides the Earth into the Northern and Southern Hemispheres?', [
                    ['خط الاستواء', 'Equator'], ['خط غرينتش', 'Prime Meridian'], ['مدار السرطان', 'Tropic of Cancer'], ['الدائرة القطبية الشمالية', 'Arctic Circle'],
                ]],
                [$hard, 'ما أكبر جزيرة في العالم؟', 'What is the largest island in the world?', [
                    ['غرينلاند', 'Greenland'], ['مدغشقر', 'Madagascar'], ['بورنيو', 'Borneo'], ['غينيا الجديدة', 'New Guinea'],
                ]],
                [$hard, 'أي دولة أفريقية لديها أكبر عدد من السكان؟', 'Which African country has the largest population?', [
                    ['نيجيريا', 'Nigeria'], ['مصر', 'Egypt'], ['إثيوبيا', 'Ethiopia'], ['جنوب أفريقيا', 'South Africa'],
                ]],
            ],
        ];
    }
}
