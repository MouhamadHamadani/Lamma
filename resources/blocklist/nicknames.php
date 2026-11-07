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
|   - digits and symbols that look like letters read as those letters (sh1t, b!tch, f@ggot, $hit)
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
| of innocent names and fails if one is blocked by mistake.
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
    ],

];
