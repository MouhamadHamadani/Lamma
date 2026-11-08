<?php

/*
|--------------------------------------------------------------------------
| Blocked nicknames (Arabic, English and Arabizi)
|--------------------------------------------------------------------------
|
| Read by App\Game\NicknameFilter through config('lamma.blocked_words'). To add a word, append it to one of the two lists below and save: there is
| nothing to build or clear except `php artisan config:clear` on a server that has cached its config (deploy.sh does it for you).
|
| Write each word in its plain, natural spelling. You do NOT need to list its tricks: before anything is compared, a nickname AND every word below go
| through the same clean-up, so one entry also catches the spaced, dotted, stretched and disguised versions of itself:
|
|   - lower case; accents, Arabic diacritics (the harakat) and the tatweel stretch removed
|   - alef, ya, ta marbuta and hamza forms folded together: أ إ آ ٱ -> ا    ى ی -> ي    ة -> ه    ؤ -> و    ئ -> ي    ک -> ك
|   - repeated letters tolerated (f-u-u-u-c-k matches fuck, but ass does not match as)
|   - digits and symbols that look like letters read as those letters (sh1t, b!tch, f@ggot, $hit), and v read as u (FvCk, Cvnt): only in this "leet" reading, so a
|     word that really has a v in it (vagina) still matches itself, and the Arabizi reading below never touches v
|   - spaces, dots, dashes, underscores and every other symbol stripped (f u c k, f.u.c.k)
|
| ARABIZI: Arabic written in Latin letters uses digits as letters (3 = ع, 7 = ح, 5 = خ, 2 = ء, 9 = ق). A word that contains a digit is compared in that
| reading, so write it the way people type it ('3ars'). A word with no digit ('sharmouta') is simply compared as letters.
|
| WHICH LIST? The danger is the Scunthorpe problem: a short bad word hiding inside an innocent name (Scunthorpe, Cassandra, Essex, Nicole, Nazir, Torpedo,
| therapist, كساب, نيكول, خولة).
|
|   'contains' : the word is blocked anywhere inside a nickname. Only for long, unmistakable words that no innocent name contains.
|   'exact'    : the word is blocked only when it IS the nickname, or one of its words (the name is split at spaces, punctuation, digits and capital
|                letters: "Big Dick", "big_dick_69", "BigDick" are blocked; "Dickinson" is not). Short or ambiguous words go here.
|
| When unsure, use 'exact'. After adding words run `vendor/bin/pest tests/Feature/Game/NicknameFilterTest.php`: it checks every entry against a long list
| of innocent names (tests/Fixtures/innocent-names.php: common Arab and Lebanese first names in Latin and Arabic letters, plus every Faker first name) and fails
| if one is blocked by mistake. Add the innocent name that worried you to that fixture when you add a word near it.
|
| SECTARIAN AND POLITICAL SLURS. Each list below has a section with this name. It is EMPTY ON PURPOSE (Phase 14.1) and is waiting for a native speaker's review:
| add a term only when you are certain it is a slur that everybody who reads it understands as one, never a word that is also an ordinary name, a religion, a
| sect, a party, a place or a political position (blocking those would lock real people out of the game). Do not guess and do not copy a list from the internet:
| an unlisted slur is a nuisance the host can remove; a listed innocent word is a player who cannot join.
*/

return [

    'contains' => [
        // English
        'fuck', 'motherfuck', 'bitch', 'bastard', 'bullshit', 'shitty', 'shithead', 'dipshit', 'asshole', 'arsehole', 'dickhead', 'cocksucker', 'blowjob', 'handjob', 'jerkoff',
        'pussy', 'dildo', 'whore', 'nigger', 'nigga', 'faggot', 'cumshot', 'pornhub', 'pornstar', 'pedophile', 'paedophile', 'hitler',

        // Arabic
        'شرموط', 'شراميط', 'قحب', 'منيوك', 'منيك', 'كس امك', 'كسمك', 'كس اختك', 'عاهر', 'لوطي', 'ديوث', 'متناك',

        // Arabizi
        'sharmou', 'sharmot', 'sharmut', 'charmou', 'charmot', 'charmut', 'kahba', 'qahba', 'kahbeh', 'kosomak', 'kosemak', 'kosumak', 'kessomak',
        'kosokhtak', 'manyak', 'manyok', 'manyouk', 'manyuk', 'metnak', 'mitnak',
        // Phase 14.1: the long, unmistakable spellings (a short or ambiguous one goes to 'exact' below)
        'khawal', '5awal', 'nayyek', 'dayous', 'dayyous', 'metnayek', 'mitnayek',

        // SECTARIAN AND POLITICAL SLURS: empty on purpose, see the header.
    ],

    'exact' => [
        // English
        'ass', 'arse', 'sex', 'shit', 'rapist', 'tit', 'tits', 'titty', 'titties', 'cum', 'dick', 'dicks', 'cock', 'cocks', 'cunt', 'cunts', 'fag', 'fags', 'piss',
        'slut', 'sluts', 'twat', 'wank', 'wanker', 'hoe', 'hoes', 'anal', 'anus', 'penis', 'vagina', 'boob', 'boobs', 'rape', 'kike', 'spic',
        'chink', 'coon', 'paki', 'retard', 'retards', 'pedo', 'nazi', 'incest', 'orgy', 'porn', 'porno', 'fuk', 'fck', 'fuq', 'shyt', 'btch',

        // Arabic
        'كس', 'زب', 'زبي', 'ايري', 'عرص', 'خول', 'نيك', 'طيز', 'خرا', 'شاذ', 'زنا',

        // Arabizi
        'kos', 'kus', 'kess', 'kuss', 'ayri', 'ayre', 'ayrak', 'zebi', 'zobbi', 'zobi', 'khara', 'kharra', '3ars', '3ayri', '3iri',
        // Phase 14.1: short or ambiguous spellings, blocked only as the whole name or one of its words
        'zeb', 'zob', 'teez', 'tiz', 'tez', '5wal', 'nayek', 'neek', 'dayus', 'louti', 'lawati', 'lawat', 'ersh',
        // 3ars with the other vowels (3ers, 3irs, 3ors, 3urs), and with a final e or sh. NB 3ars and 3ors are also how people type عرس "wedding": a nickname meant that
        // way is turned away too (accepted: exact words only, so "Wedding" and "عرس" are fine).
        '3ers', '3irs', '3ors', '3urs', '3erse', '3arse', '3ersh', '3arsh',

        // SECTARIAN AND POLITICAL SLURS: empty on purpose, see the header.
    ],

];
