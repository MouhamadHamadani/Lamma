<?php

/*
 * Variety pack (Phase 13): the questions that are not "pick one of four": 12 true/false, 10 closest-number and 8 picture questions. A pack is
 * a file that returns [category slug => list of rows] and ContentSeeder merges it into the category's own questions; the row format is the one
 * in ../questions.php plus the optional fifth element. Pictures are the SVG drawings in ../images (our own art: shapes, clock faces, flags).
 *
 *   true_false:      [difficulty, arabic, english, true|false, ['type' => 'true_false', 'fact' => 'arabic|english']]
 *   closest_number:  [difficulty, arabic, english, 1969, ['type' => 'closest_number', 'tolerance' => 10, 'fact' => 'arabic|english']]
 *   picture:         [difficulty, arabic, english, [correct, wrong, wrong, wrong], ['image' => 'flag-japan.svg', 'fact' => ...]]
 *
 * Only facts that are always true. A closest-number tolerance left out is 25% of the answer (at least 1): years need their own.
 * Real photos are a content job for the admin (Filament question form or the CSV import), not for this file.
 */
return [
    'science' => [
        ['easy', 'الشمس نجم.', 'The Sun is a star.', true, ['type' => 'true_false', 'fact' => 'الشمس نجم متوسط الحجم، وهي أقرب نجم إلينا.|It is a medium-sized star, and the closest one to us.']],
        ['easy', 'ينتقل الصوت أسرع من الضوء.', 'Sound travels faster than light.', false, ['type' => 'true_false', 'fact' => 'لهذا نرى البرق قبل أن نسمع الرعد.|That is why we see lightning before we hear thunder.']],
        ['easy', 'لا يضرب البرق المكان نفسه مرتين أبدًا.', 'Lightning never strikes the same place twice.', false, ['type' => 'true_false', 'fact' => 'تتعرض المباني العالية للصواعق عدة مرات في السنة.|Tall buildings are struck by lightning several times a year.']],
        ['hard', 'اليوم على كوكب الزهرة أطول من سنته.', 'A day on Venus is longer than its year.', true, ['type' => 'true_false', 'fact' => 'يدور كوكب الزهرة حول نفسه ببطء شديد: دورة واحدة تستغرق نحو 243 يومًا أرضيًا، وسنته نحو 225 يومًا.|Venus spins so slowly that one turn takes about 243 Earth days, while its year is about 225.']],
        ['medium', 'كم تبعد الشمس عن الأرض تقريبًا، بملايين الكيلومترات؟', 'About how far is the Sun from the Earth, in millions of kilometres?', 150, ['type' => 'closest_number', 'fact' => 'يحتاج ضوء الشمس إلى نحو 8 دقائق ليصل إلينا.|Sunlight takes about 8 minutes to reach us.']],
        ['hard', 'في أي عام صُنّف بلوتو كوكبًا قزمًا؟', 'In which year was Pluto reclassified as a dwarf planet?', 2006, ['type' => 'closest_number', 'tolerance' => 5, 'fact' => 'اتخذ الاتحاد الفلكي الدولي هذا القرار.|The International Astronomical Union made that decision.']],
    ],

    'animals-nature' => [
        ['easy', 'الخفافيش طيور.', 'Bats are birds.', false, ['type' => 'true_false', 'fact' => 'الخفاش هو الثديي الوحيد القادر على الطيران الحقيقي.|The bat is the only mammal that can truly fly.']],
        ['medium', 'تخزّن الجمال الماء في أسنمتها.', 'Camels store water in their humps.', false, ['type' => 'true_false', 'fact' => 'تخزّن الأسنمة الدهون، وهذا يساعد الجمل على تحمّل الجوع والعطش وقتًا طويلًا.|Humps store fat, which helps a camel go a long time without food or water.']],
        ['easy', 'تعيش الدببة القطبية حول القطب الجنوبي.', 'Polar bears live around the South Pole.', false, ['type' => 'true_false', 'fact' => 'تعيش الدببة القطبية حول القطب الشمالي.|Polar bears live around the North Pole.']],
    ],

    'geography' => [
        ['medium', 'أنتاركتيكا صحراء.', 'Antarctica is a desert.', true, ['type' => 'true_false', 'fact' => 'هي أكبر صحراء في العالم لأن أمطارها قليلة جدًا.|It is the largest desert in the world because it gets very little rain.']],
        ['medium', 'يجري نهر النيل من الجنوب إلى الشمال.', 'The Nile flows from south to north.', true, ['type' => 'true_false', 'fact' => 'يصبّ النيل في البحر الأبيض المتوسط.|The Nile flows into the Mediterranean Sea.']],
        ['hard', 'قمة إيفرست هي أعلى جبل في العالم إذا قِسناه من قاعدته إلى قمته.', 'Mount Everest is the tallest mountain on Earth when measured from base to peak.', false, ['type' => 'true_false', 'fact' => 'جبل مونا كيا في هاواي أطول منه من القاعدة إلى القمة، لكن معظمه تحت المحيط.|Mauna Kea in Hawaii is taller from base to peak, but most of it is under the ocean.']],
        ['medium', 'ما ارتفاع قمة إيفرست تقريبًا بالأمتار؟', 'About how many metres tall is Mount Everest?', 8849, ['type' => 'closest_number', 'tolerance' => 2000, 'fact' => 'اتفقت نيبال والصين عام 2020 على أن ارتفاعها 8848.86 مترًا.|In 2020, Nepal and China agreed on a height of 8848.86 metres.']],
        ['easy', 'لأي دولة هذا العلم؟', "Which country's flag is this?", ['اليابان|Japan', 'الصين|China', 'كوريا الجنوبية|South Korea', 'فيتنام|Vietnam'], ['image' => 'flag-japan.svg', 'fact' => 'يُسمّى العلم «هينومارو»، أي قرص الشمس.|The flag is called Hinomaru, which means "sun disc".']],
        ['easy', 'أي دولة لها هذا العلم الأخضر والأبيض والأحمر؟', 'Which country has this green, white and red flag?', ['إيطاليا|Italy', 'فرنسا|France', 'ألمانيا|Germany', 'إسبانيا|Spain'], ['image' => 'flag-italy.svg']],
        ['medium', 'هذا هو علم أي دولة؟', 'Whose flag is this?', ['ألمانيا|Germany', 'بلجيكا|Belgium', 'النمسا|Austria', 'إسبانيا|Spain'], ['image' => 'flag-germany.svg', 'fact' => 'ألوانه من الأعلى إلى الأسفل: الأسود والأحمر والذهبي.|From top to bottom its colours are black, red and gold.']],
        ['medium', 'لأي دولة ينتمي هذا العلم؟', 'Which country does this flag belong to?', ['السويد|Sweden', 'فنلندا|Finland', 'النرويج|Norway', 'الدنمارك|Denmark'], ['image' => 'flag-sweden.svg']],
    ],

    'history' => [
        ['hard', 'كم سنة استمرت حرب المئة عام؟', "For how many years did the Hundred Years' War last?", 116, ['type' => 'closest_number', 'fact' => 'بدأت عام 1337 وانتهت عام 1453.|It began in 1337 and ended in 1453.']],
    ],

    'arab-world' => [
        ['medium', 'في أي عام أعلن لبنان استقلاله؟', 'In which year did Lebanon declare its independence?', 1943, ['type' => 'closest_number', 'tolerance' => 5, 'fact' => 'يحتفل اللبنانيون بعيد الاستقلال في 22 تشرين الثاني.|Independence Day is celebrated on 22 November.']],
    ],

    'landmarks' => [
        ['medium', 'يمكن رؤية سور الصين العظيم من القمر بالعين المجردة.', 'The Great Wall of China can be seen from the Moon with the naked eye.', false, ['type' => 'true_false', 'fact' => 'السور طويل جدًا لكنه أضيق من أن يُرى من القمر.|The wall is very long, but far too narrow to be seen from the Moon.']],
        ['medium', 'في أي عام اكتمل بناء برج إيفل؟', 'In which year was the Eiffel Tower completed?', 1889, ['type' => 'closest_number', 'tolerance' => 10, 'fact' => 'بُني من أجل المعرض العالمي في باريس.|It was built for the World\'s Fair in Paris.']],
    ],

    'food-drink' => [
        ['medium', 'حبوب القهوة هي بذور ثمرة.', 'Coffee beans are the seeds of a fruit.', true, ['type' => 'true_false', 'fact' => 'تُسمّى ثمرة القهوة «الكرز»، وبذورها هي ما نحمّصه.|The fruit is called a coffee cherry, and its seeds are what we roast.']],
    ],

    'everyday-life' => [
        ['easy', 'كم ساعة في الأسبوع الواحد؟', 'How many hours are there in one week?', 168, ['type' => 'closest_number', 'fact' => '24 ساعة × 7 أيام.|24 hours × 7 days.']],
        ['easy', 'كم ورقة في مجموعة ورق اللعب القياسية، من دون ورقتي الجوكر؟', 'How many cards are in a standard deck of playing cards, without the jokers?', 52, ['type' => 'closest_number', 'fact' => 'أربعة أنواع في كل منها 13 ورقة.|Four suits of 13 cards each.']],
        ['medium', 'كم دقيقة في اليوم الكامل؟', 'How many minutes are there in a full day?', 1440, ['type' => 'closest_number', 'fact' => '24 ساعة × 60 دقيقة.|24 hours × 60 minutes.']],
        ['easy', 'كم الساعة في الصورة؟', 'What time does the clock show?', ['4:30', '5:30', '6:20', '6:25'], ['image' => 'clock-4-30.svg']],
        ['medium', 'ما الوقت الذي تشير إليه هذه الساعة؟', 'What time is shown on this clock?', ['10:10', '2:50', '10:02', '9:50'], ['image' => 'clock-10-10.svg', 'fact' => 'تُعرض الساعات في الإعلانات غالبًا على 10:10 لأن العقارب تبدو كابتسامة.|Watches in adverts are often set to 10:10 because the hands look like a smile.']],
    ],

    'general-knowledge' => [
        ['easy', 'كم ضلعًا للشكل في الصورة؟', 'How many sides does this shape have?', ['6', '5', '7', '8'], ['image' => 'shape-hexagon.svg', 'fact' => 'خلايا شمع العسل على شكل سداسي.|Honeycomb cells are hexagons.']],
        ['easy', 'كم نجمة في الصورة؟', 'How many stars are in the picture?', ['7', '6', '8', '9'], ['image' => 'shape-stars.svg']],
    ],

    'rugby' => [
        ['easy', 'كم لاعبًا في فريق اتحاد الرغبي داخل الملعب؟', 'How many players does a rugby union team have on the pitch?', 15, ['type' => 'closest_number', 'fact' => 'في رغبي الدوري يلعب 13 لاعبًا.|A rugby league team has 13 players.']],
    ],
];
