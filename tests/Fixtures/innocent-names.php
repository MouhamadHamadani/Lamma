<?php

/*
 * Names that must NEVER be turned away by the nickname filter (tests/Feature/Game/NicknameFilterTest.php reads this file): common Arab and Lebanese first names in Latin
 * and in Arabic letters, a few family names, and the ways people dress a name up for a screen (a number, an underscore, a heart, "Abu"). When a word is added to
 * resources/blocklist/nicknames.php, add here the innocent name that sits next to it (Nayef next to nayek, Khawla next to khawal) so it stays safe.
 *
 * The 53 names the test lists inline and every Faker first name are checked as well.
 */

return [
    'latin' => [
        'Ahmad', 'Ahmed', 'Aya', 'Adam', 'Adel', 'Adnan', 'Alaa', 'Amal', 'Amer', 'Amin', 'Amira', 'Anas', 'Anwar', 'Arwa', 'Asma', 'Ayman', 'Aziz',
        'Bashar', 'Bassam', 'Bassel', 'Basma', 'Bilal', 'Boutros', 'Carine', 'Carla', 'Charbel', 'Chadi', 'Dalia', 'Dana', 'Dania', 'Dayan', 'Dayana', 'Dima', 'Diala',
        'Elie', 'Elias', 'Ershad', 'Ersan', 'Fadi', 'Fady', 'Fahed', 'Farid', 'Fares', 'Farah', 'Fatima', 'Fawzi', 'Firas', 'Ghada', 'Ghassan',
        'Hadi', 'Hala', 'Hamza', 'Hana', 'Hani', 'Hassan', 'Hayat', 'Hiba', 'Hicham', 'Hind', 'Hoda', 'Hussein', 'Ibrahim', 'Imad', 'Iyad',
        'Jad', 'Jamal', 'Jamil', 'Jana', 'Jawad', 'Jinan', 'Joelle', 'Jumana', 'Karam', 'Karim', 'Khaled', 'Khalil', 'Khawla', 'Khawlani',
        'Lamia', 'Lara', 'Layal', 'Layla', 'Leen', 'Lina', 'Lotfi', 'Loutfi', 'Louay', 'Lawand', 'Lubna',
        'Maher', 'Majd', 'Malek', 'Mariam', 'Marwan', 'Maya', 'Mazen', 'Metn', 'Mira', 'Mitri', 'Mona', 'Moustafa', 'Mounir',
        'Nabil', 'Nada', 'Nadim', 'Nadine', 'Nader', 'Naji', 'Najla', 'Najwa', 'Nasser', 'Nawal', 'Nayef', 'Nayla', 'Nizar', 'Noor', 'Nour', 'Noura',
        'Omar', 'Osama', 'Rabih', 'Rami', 'Rana', 'Rania', 'Rasha', 'Reem', 'Rima', 'Rola', 'Roula', 'Ryan',
        'Saad', 'Sabine', 'Said', 'Salma', 'Salim', 'Samar', 'Sami', 'Samir', 'Sana', 'Sara', 'Selim', 'Shadi', 'Sherine', 'Soha',
        'Tala', 'Tamer', 'Tarek', 'Teeba', 'Tezcan', 'Tiziana', 'Walid', 'Wael', 'Wissam', 'Yara', 'Yasmine', 'Yasser', 'Youssef',
        'Zahra', 'Zaid', 'Zeba', 'Zeina', 'Zeinab', 'Ziad', 'Zouheir',
        // the ways a name is dressed up
        'Abu Hadi', 'Um Karim', 'Sara 7', 'Hadi_92', 'Layla ♥', 'Tarek-2024', 'Nour.Haddad', 'Dr. Samir', 'Mimi', 'Joe', 'Tony', 'Rita',
    ],
    'arabic' => [
        'أحمد', 'علي', 'عمر', 'حسن', 'حسين', 'هادي', 'كريم', 'رامي', 'سامي', 'سمير', 'سامر', 'طارق', 'وليد', 'وائل', 'زياد', 'فادي', 'فارس',
        'فرح', 'فاطمة', 'فادية', 'هالة', 'هناء', 'هند', 'هبة', 'هدى', 'هيفاء', 'إبراهيم', 'عماد', 'إيهاب', 'إياد', 'جاد', 'جمال', 'جنى', 'جينان',
        'خالد', 'خليل', 'خديجة', 'خولة', 'كمال', 'كندة', 'ليال', 'لينا', 'لمى', 'لارا', 'ليلى', 'لبنى', 'لؤي', 'لطفي', 'محمود', 'ماجد', 'ماهر', 'ملك',
        'مريم', 'مايا', 'مازن', 'ميرا', 'محمد', 'مصطفى', 'منير', 'منى', 'نبيل', 'نادين', 'نادية', 'ندى', 'نادر', 'ناجي', 'نجوى', 'ناصر', 'نوال',
        'نايف', 'نايلة', 'نور', 'نورا', 'نزار', 'أسامة', 'ربيع', 'رشيد', 'رنا', 'رانيا', 'رشا', 'ريم', 'ريما', 'ريبال', 'رولا', 'صباح', 'سعيد',
        'سلمى', 'سليم', 'سناء', 'سارة', 'سوسن', 'شادي', 'شيرين', 'سعاد', 'سها', 'سهى', 'تالا', 'تامر', 'تانيا', 'وديع', 'وسام', 'يارا', 'ياسمين',
        'ياسر', 'يوسف', 'يحيى', 'زهرة', 'زينة', 'زين', 'زينب', 'زهير', 'ديما', 'ديالا', 'ديانا', 'عرسال', 'عروبة', 'عبير', 'عفاف', 'غادة', 'غسان',
        'بشار', 'بسام', 'باسل', 'بسمة', 'بلال', 'بطرس', 'تيما', 'ثريا', 'جميل', 'جواد', 'حنان', 'حياة', 'حكمت', 'دلال', 'دانا', 'ذكرى',
        // the ways a name is dressed up
        'أبو هادي', 'أم كريم', 'سارة 7', 'هادي_92', 'ليلى ♥', 'نور الهدى', 'عبد الله', 'عبد الرحمن',
    ],
];
