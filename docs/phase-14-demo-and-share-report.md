# Lamma · لمّة — Phase 14 report: a demo without signup, landing page fixes, a nickname filter, a result share card and funnel analytics

Written 2026-11-08. `CLAUDE.md` is gitignored, so its Phase 14 edits are local only. Nothing has been pushed: a push to `main` deploys.

| Commit | What |
| --- | --- |
| `dee535e` | 1. The no-signup demo: route, controller, `DemoRoom`, the two bots and `BotAnswer`, access rules, limits, prune, exclusions; the funnel's event names |
| `33a5726` | 2. Landing page: Try a demo, the free line, the how-it-works clip, and what a browser check found |
| `9778a71` | 3. The nickname filter |
| `5b7182c` | 4. The result share card |
| `1b30d34` | 5. Funnel analytics and the dashboard's Funnel widget |
| `fbb0a7f` | 6. Share tags, the placeholder og:image and the launch check |
| (this commit) | The browser smoke test for `/demo`, HANDOFF section 13, the manual test plan, deployment notes, this report |

**One thing I did wrong, first.** While trying to check the new migration I ran `php artisan migrate:fresh --env=testing`. There is no `.env.testing`, so it ran against your **local MySQL `quiz_party`** and emptied it. I re-seeded it straight away
(`php artisan db:seed`: the admin, 1,258 questions in 21 categories, the 5 seeded test users). **Anything in that database that was not seed data (rooms, accounts or questions you made by hand while testing) is gone.** Nothing outside your local machine was touched.
I wrote the lesson into `CLAUDE.md` ("Gotchas"). Tests never use that database (SQLite in memory).

---

## 1. What was built, per section

### 1. The demo

- **Flow:** `/demo` explains it (under 768 px it says to open it on a bigger screen, and the button still works) and **Start a demo game** posts to `DemoController::start`, which asks `App\Game\DemoRoom`: 5 per IP per hour (`RateLimiter`, counted after a successful start),
  fixed settings (4 questions, 15 s, Mixed, `arab-world` + `general-knowledge`, Both, double final on, selfies off, Classic), a Classic room through the existing `RoomManager::create` (which now takes `?User` and extra columns), the two bots, and the host cookie. The visitor lands on `/host/{code}`.
- **A host without an account.** `rooms.host_id` was already nullable (migration `2026_10_09`). New: `rooms.is_demo`, `rooms.host_token_hash` (the sha256 of the token in the encrypted, httpOnly `lamma_demo_host` cookie, like the guest cookie; one token per browser for all its demos), `room_players.is_bot`.
  `PlayerIdentity::hostFor($room)` answers "who runs this screen" for both kinds of room; the `room.host` middleware (a real room: `auth` + the policy exactly as before; a demo: the cookie, else 403), `RoomPolicy::host(?User, Room)`, `Room::isHostedBy`, and a `demo` guard on the presence channel
  (`['player', 'web', 'demo']`) use it. **Real rooms keep their rules:** a demo cookie opens nothing but its own room, a logged-in stranger, a player of the room and another demo's cookie are all 403, and a demo cookie on a real room redirects to login or is a 403 (tests for each).
- **The bots.** Sara (EN) and Karim (AR) are real `room_players` rows (`is_bot`, ready, connected, no token, no account), so the engine's early-reveal rule counts them without any change. `Bots::plan()` (called from `GameEngine::announce()` for a demo room) queues one `BotAnswer` per bot, delayed to a random moment
  between 25% and 85% of the question's time, right with probability 65%. A number question is answered inside the tolerance (right) or 1.3-2.5 tolerances away (wrong). A late or repeated job does nothing.
  Presence sync never marks a bot gone, the host cannot remove one, a demo cannot start without a real connected player, and after 90 seconds with nobody real the lobby says **Waiting for you? Scan the code** while the bots stay ready.
- **Excluded from everything:** `question_seen` (bots), claims (`ClaimGuestResults` skips bots and demo rooms), My games and the statistics (`Room::real()` on `PlayerStats`, games per day, active rooms, most-played categories, the hardest questions, the question list's Times played and % correct, the Rooms count), the admin room list (shows "Demo (no account)", bots are not players), and GA (no `game_started`, `player_joined` or `game_finished` for a demo; nothing about a bot, ever).
- **End and clean-up:** the host podium and the phone's results offer **Host your own game** (`/register`) and **Join a friend's game** (`/join`) instead of Play again / New game / Save your score; `RoomManager::playAgain` refuses a demo. `lamma:prune` closes and deletes demo rooms older than 2 hours (phones are told; everything cascades), after tallying each into the new `demo_stats` table.
- **Everything else is the real game:** the same engine, screens, sounds and timers. A browser test plays a whole demo.

### 2. Landing page (checked in a browser at 390 and 1440 px, Arabic and English, before fixing anything)

| Your list | What I found | What I did |
| --- | --- | --- |
| Hero mockup labels "Sara · EN0:12", "علي · ع0:12" | **Not garbled on screen**: the name and the timer sit 35 px apart. The run-together text came from reading the text without layout. | I still made the name, a small **EN** / **ع** pill and the timer three separate elements with a gap, so even a text reader gets "Sara EN 0:12". |
| English strings on the Arabic page ("Answer locked in", "Question 3") | They are **on purpose**: the English phone and the English card of the bilingual demo, and the Arabic ones on the English page. Translating them would remove the point of the section. **But they were not tagged**: the English phone and card sat under `lang="ar"`, so a screen reader would read English with an Arabic voice. | I tagged every demo phone and card with its own `lang` and `dir`. A test fails if any English text on the Arabic page is outside a `lang="en"` element (apart from names and codes). **If you want them translated anyway, say so.** |
| Header navigation twice | Not a visual duplicate (the phone menu was `display:none`), but it was in the DOM and two navigations had no names. | The phone menu is rendered only while open (`<template x-if>`), the header navigation is **Main menu** and the footer's **Footer menu** («القائمة الرئيسية», «قائمة أسفل الصفحة»). |
| Footer avatar badges "S 2", "A 1", "M 3" | A decorative podium (three avatars on three bars) in the navy call-to-action band, already `aria-hidden`. Clear to the eye. | Left as it is. |
| Logo images with empty alt | The mark next to the wordmark is decorative, so empty alt is right there. **Real:** on a phone narrower than 380 px the wordmark was `display:none`, so a logo link (the phone lobby's) had no name at all; and the e-mail logo had `alt=""`. | The wordmark is now `sr-only` at that width (phone game, lobby, results), the e-mail logo's alt is the app name. A test checks every link that holds a logo has a name. |

Then the additions: **Try a demo** (navy outline) beside **Host a game**, the line **Hosting and playing are free for now.** (in both lang files, behind `lamma.landing.show_free_line` / `LAMMA_LANDING_FREE_LINE`, default on; under the hero button and under the navy band's), and **See how it works** opens `public/media/how-it-works.mp4` in a dialog
(`preload="none"`, optional poster `how-it-works.jpg|png|webp`, optional captions `how-it-works.en.vtt` / `.ar.vtt`, pauses on close) or scrolls to the three steps if the file is not there (tried with a temporary file, then removed). **Host a game** now goes to room creation for everyone: a guest is sent to log in by the page and comes back (this is what lets a sign-up be counted as a host's).

### 3. Nickname filter

`resources/blocklist/nicknames.php` (the header says how to extend it) is loaded by `config('lamma.blocked_words')`: **`contains`** words (long, unmistakable) and **`exact`** words (short or ambiguous: blocked only when they are the name or one of its words, split at spaces, punctuation, digits, capitals). `App\Game\NicknameFilter`
cleans a name **and every listed word** the same way before comparing: lower case, accents, Arabic diacritics and tatweel removed, alef / ya / ta marbuta / hamza folded, digits and symbols read as letters (leet), a separate **Arabizi** reading for a word that contains a digit (`3ars`: there 3 is ع and can never match "Ears"), spaces and punctuation stripped. Repeated letters are tolerated **per word**
(`fuuuck` is blocked; `ass` still needs both s, so "As" and "Asa" are fine). Applied at join, rename ("Please choose another nickname.", the same inline error as "taken", never naming a word, checked before "taken") and when a name is **displayed**: `RoomPlayer::nickname` shows **Player** (لاعب) for a name the list refuses today, on every screen and in
every broadcast, while the stored name stays for the unique index, Play again and the admin. Tests: 130, including the evasions, 53 innocent names and **every Faker first name**. A bug the tests found: a combining mark inside an Arabic name split it into two words (كسّار became "كس" + "ار"); fixed. "Shiite" and "Therapist" were caught by `shit` and `rapist`; both words moved to the exact list.

### 4. Result share card

`resources/js/share-card.js` draws the 1080x1350 PNG on a canvas with Alpine: brand colours read from the CSS tokens (no hex in the file), Baloo Bhaijaan 2 after `document.fonts.load`, the winner (or the tie / "No winner this time"), the top three with places and scores, the player's own place on a phone, the logo, `config('app.url')`'s host. Right to left for Arabic, a long name shrinks then ellipsizes, no photo, no room code, no id: `App\Game\ShareCard` is the whole list of what may be on it.
`<x-lamma.share-button>` (in the gallery, a new `share` icon) is on the phone results, the host podium and the demo's end screens: Web Share API with the file where `canShare({files})` allows, a blob download otherwise, a closed share sheet does nothing, a status line that fades in only behind `motion-safe:`. `share_clicked` (surface, method) goes through the consent-gated analytics.
I drew it in a real browser in English and Arabic (mixed Arabic and Latin names, an over-long name) and fixed an overlap between the "Your place" band and the footer line.

### 5. Funnel analytics

Through `App\Support\Analytics`, no names, codes or accounts, nothing on bots: `demo_started`, `demo_finished`, `host_signup`, `room_created`, `game_started`, `game_finished` (now with a player-count bucket `1 / 2-4 / 5-8 / 9+` and the mode), `rematch_clicked`, `share_clicked`, `join_page_viewed` (once per browser session; dropped without consent). The **Funnel · last 30 days** widget on the Filament dashboard
is computed from the database (`GameStats::funnel`): rooms created, games started and finished (each with its share of the step before), the rematch rate, the average players, and **Demo games** as a figure of its own (they are in none of the totals). Demo to signup is attributed: a registration on a browser still holding a demo host cookie counts (`AttributeDemoSignup`); a `host_signup` event is sent when a sign-up was heading for room creation or came from such a browser.

### 6. Launch readiness

`php artisan lamma:doctor` (run locally): the placeholders it flags are listed in section 5 below. The share tags (`og:title`, `og:description`, `og:image` with width 1200, height 630, type and alt, `og:url`, `og:locale` and the other language as an alternate, `twitter:card=summary_large_image` and its title, description and image, a canonical link and a plain description) are now on `/`, `/join` and `/demo`, in both
languages, and on no screen that carries a room; the join page's address is shared without its query string. **No official brand sheet exists, so `public/brand/og-image.png` is a simple branded picture I made:** our placeholder logo, the brand colours, Baloo Bhaijaan 2, the English and Arabic tagline, the address (source `docs/design/og-image-source.html`, rendered with Playwright, 1200x630, 63 KB). It is listed in `lamma.placeholders`, so `lamma:doctor` warns about it until you replace it.

---

## 2. Deviations from the prompt, and why

1. **`play_again_clicked` is now `rematch_clicked`.** The prompt lists `rematch_clicked` as a new event and the existing one was the same click; two names for one event would split the numbers. GA4 shows the old name for earlier data only.
2. **A `demo_stats` table.** The prompt says `lamma:prune` deletes a demo after 2 hours and also wants the dashboard to show demo games and demo-to-signup "from the database"; once the room is gone nothing is left to count. So prune tallies a demo (started, finished, per day) into `demo_stats` first, and the sign-up count is bumped at registration time.
   Attribution is by the **laptop's** demo cookie only: someone who signs up on their phone (the one that played) is not attributed.
3. **The English phone and card on the Arabic landing page are tagged, not translated** (section 1.2 above).
4. **`Room::real()` (a new scope) instead of changing `Room::completed()`.** A demo's own end screen needs `completed()`; every statistic and list asks for `real()->completed()`.
5. **A bot's answer needs the queue worker.** The prompt asks for "a delayed job", so that is what it is; there is no host-screen fallback like the one for reveals. See annoyance 1.
6. **"Host a game" goes to room creation for everyone** (it went to login for guests), so a guest comes back to the page they wanted and a sign-up can be counted as a host's.
7. **A name the list refuses is displayed as "Player" everywhere**, not only on the host screen: one accessor on `RoomPlayer::nickname` means no screen, broadcast or new view can forget it. The stored name is kept (raw access for the unique index, Play again, the admin's player list).
8. **Funnel "rooms created" includes the lobbies Play again makes** (they are rooms hosts created); the rematch rate is finished games that were played again.
9. **A demo shows a "Bot" tag** on Sara and Karim (host lobby, phone lobby and leaderboard), which the prompt did not ask for: nobody should mistake them for people.
10. **Existing tests changed on purpose:** `AnalyticsTest` (the renamed event and the event list), `DoctorTest` and `ProductionTest` (15 placeholders now), `QuestionTypesTest` (its migration test rolled back "the newest migration" with `--step=1`, which is now this phase's: it rolls back its own file with `--path`), `Browser/SmokeTest` (+ `/demo`).

---

## 3. Test results

| Check | Result |
| --- | --- |
| Baseline before any change | Pint passed, PHPStan 0 errors, Pest 2,264 tests: 2,258 passed, 6 skipped, 106,861 assertions (1,001 s) |
| `composer test` at the end | **Pint passed, PHPStan level 7: 0 errors, Pest 2,547 tests: 2,541 passed, 6 skipped (the known email-verification skips), 108,047 assertions**, 618 s, no warnings. (One earlier full run had one failure, a documentation test that found a path in `docs/deployment.md` that does not exist yet; I reworded the line and the final run is clean.) |
| New test files | `DemoTest` 42, `DemoBotsTest` 16, `DemoExclusionsTest` 28, `NicknameFilterTest` 130, `ShareCardTest` 21, `LandingPhase14Test` 19, `ShareMetaTest` 11, `FunnelWidgetTest` 15; browser `DemoGameTest` 2, `ShareCardTest` 7 (and one new case in browser `AnalyticsTest`) |
| What they prove | Every limit and exclusion of the brief; bots with a faked clock (their moments, 65%, right and wrong, a number guess, true/false, late and repeated jobs, the early reveal with them); who can open a demo and that a real room's rules are unchanged; prune and its tally; the evasions and 53 innocent names and every Faker first name for the filter; what is, and is not, on the share card; the button in both languages; the meta tags per page and language; the funnel numbers; events only through the whitelist |
| Browser tests, per file | **145 of 146 pass, each file on its own:** `SmokeTest` 44 (now with `/demo`), `AnalyticsTest` 8, `DemoGameTest` 2, `ShareCardTest` 7, `FullGameTest` 1, `HostScreensTest` 14, `SubcategoriesTest` 10, `PhotoScreensTest` 10, `SelfieTest` 10, `SoundTest` 4, `VoteModeTest` 4, `ScoringTest` 7, `QuestionTypesTest` 13, `MotionTest` 11 of 12. The one failure is `MotionTest > plays the expected sounds`: "Target crashed", the crash `CLAUDE.md` has recorded on this laptop since Phase 10. **Hangs:** in a sweep of all files, `DemoGameTest`, `SubcategoriesTest`, `ScoringTest` and `QuestionTypesTest` hit my 560 s timeout, and one `QuestionTypesTest` case hung once more by itself; each passed when run again after stopping every leftover Playwright process (the pattern `CLAUDE.md` records), so I do not count them as failures, but I could not make a whole-folder run clean |
| Production build | no errors. `app.js` 94.91 kB raw / 28.77 kB gzip (Phase 13: 90.71 / 26.72: +4.2 kB raw, +2.0 kB gzip, the share card), `app.css` 77.12 kB raw / 14.66 kB gzip (Phase 13: 76.04 / 14.20) |
| Not checked | **A live game with Reverb and a real phone** (the browser tests have no Reverb: the host screen's tick and the phone's poll drive them; I did play a whole demo by hand in two browser windows with Reverb and a queue worker running, which worked), **the Web Share API on a real phone**, a link preview in a real chat app, the Arabic keyboard on a real phone. |

---

## 4. New Arabic strings that need a native review

| English | Arabic |
| --- | --- |
| Bot | روبوت |
| Demo | تجريبي |
| Scan the code with your phone to play. The two bots are ready and waiting. | امسح الرمز بهاتفك لتلعب. الروبوتان جاهزان وينتظران. |
| Waiting for you? Scan the code | بانتظارك؟ امسح الرمز |
| Unlocks when you have joined from your phone and tapped Ready. | يُفتح عندما تنضم من هاتفك وتضغط «جاهز». |
| That was the demo. Ready for a game with your own friends? | كانت هذه نسخة تجريبية. هل أنت جاهز للعب مع أصدقائك؟ |
| Host your own game | استضف لعبتك الخاصة |
| Join a friend's game | انضم إلى لعبة صديق |
| Liked it? | أعجبتك؟ |
| Host your own game, or join a friend's. | استضف لعبتك الخاصة، أو انضم إلى لعبة صديق. |
| You have started a lot of demos. Please try again in :minutes minutes. | بدأت عددًا كبيرًا من النسخ التجريبية. حاول مجددًا بعد :minutes دقيقة. |
| The demo is not ready right now. Please try again later. | النسخة التجريبية غير جاهزة الآن. حاول مرة أخرى لاحقًا. |
| Start the demo on the big screen | ابدأ النسخة التجريبية على الشاشة الكبيرة |
| A room opens with a code and a QR code. | تُفتح غرفة برمز ورمز QR. |
| Scan the code with your phone | امسح الرمز بهاتفك |
| Pick your language, choose a nickname and tap Ready. | اختر لغتك واسمك المستعار واضغط «جاهز». |
| Play 4 quick questions against two bots | العب 4 أسئلة سريعة ضد روبوتين |
| Same game, same screens, same sounds. It takes about two minutes. | اللعبة نفسها والشاشات نفسها والأصوات نفسها. تستغرق نحو دقيقتين. |
| Try a demo | جرّب نسخة تجريبية |
| Feel the game in two minutes. | جرّب اللعبة في دقيقتين. |
| No account needed. Open this on a laptop or TV, then scan the code with your phone. | لا حاجة إلى حساب. افتح هذه الصفحة على حاسوب أو تلفاز، ثم امسح الرمز بهاتفك. |
| You're on a small screen. The demo works best on a laptop or TV: open this page there, then scan the code with your phone. | شاشتك صغيرة. تعمل النسخة التجريبية بأفضل شكل على حاسوب أو تلفاز: افتح هذه الصفحة هناك، ثم امسح الرمز بهاتفك. |
| Start a demo game | ابدأ لعبة تجريبية |
| Want your own games? | تريد ألعابك الخاصة؟ |
| How the demo works | كيف تعمل النسخة التجريبية |
| Hosting and playing are free for now. | الاستضافة واللعب مجانيان في الوقت الحالي. |
| Main menu | القائمة الرئيسية |
| Footer menu | قائمة أسفل الصفحة |
| Please choose another nickname. | يرجى اختيار اسم مستعار آخر. |
| Player | لاعب |
| Share result | شارك النتيجة |
| Result shared | تمت مشاركة النتيجة |
| Result saved to your device | تم حفظ النتيجة على جهازك |
| We couldn't make the picture. Try again. | تعذّر إنشاء الصورة. حاول مرة أخرى. |
| My result on Lamma | نتيجتي في لمّة |

Also new Arabic text, not in `lang/`: the **blocklist** `resources/blocklist/nicknames.php` (Arabic and Arabizi words: please have a native reader check what is missing and what could wrongly catch a real name), and the tagline on the placeholder `og-image.png` (it reuses the existing «سهرة الأسئلة للكل»).
Worth a closer look: «روبوت» for **Bot** (the tag next to Sara and Karim), «تجريبي» for **Demo**, «الروبوتان» (the dual), «يُفتح عندما تنضم من هاتفك وتضغط «جاهز».» (spoken to one person: the demo's player), «شارك النتيجة» and «تم حفظ النتيجة على جهازك».

---

## 5. What needs you

**Placeholders `lamma:doctor` still flags** (15; replace each under the **same file name**, then delete its line from `placeholders` in `config/lamma.php`; I did not invent brand files):

- Logos and icons, in `public/brand/`: `lamma-icon.svg`, `lamma-logo-horizontal.svg`, `lamma-logo-horizontal-dark.svg`, `lamma-logo-stacked.svg`, `lamma-logo-stacked-dark.svg`, `lamma-app-icon.svg`, `lamma-app-icon.png`.
- The browser icons: `public/favicon.svg`, `public/favicon.ico`, `public/apple-touch-icon.png`.
- The four sounds, in `public/sounds/`: `podium.wav`, `question-start.wav`, `reveal.wav`, `tick.wav`.
- **New this phase, mine:** `public/brand/og-image.png` (1200x630, the picture a pasted link shows). The share card on a phone also draws `lamma-logo-stacked-dark.svg`, so the official logo appears on shared results as soon as it replaces the placeholder.

The doctor also fails or warns on this machine for things that are right for local development and must be right on the server (debug, http URL, mail log, the placeholder GA ID, Redis, SESSION_SECURE_COOKIE): `docs/deployment.md` covers them.

**Deploy:**

- **One new migration:** `2026_11_09_090000_add_demo_rooms` (`rooms.is_demo`, `rooms.host_token_hash`, `room_players.is_bot`, the `demo_stats` table). It only adds columns and a table; everything that exists stays a real room and a real player.
- **Restart the queue workers and Reverb** (`deploy.sh` does): a new job class (`BotAnswer`) and a new channel guard. The bots only answer while a worker runs.
- **One new, optional environment value:** `LAMMA_LANDING_FREE_LINE` (default `true`; set `false` when "Hosting and playing are free for now." stops being true). It is in both `.env.example` files.
- No nginx change. Optional: put the clip `public/media/how-it-works.mp4` (and a poster and `.vtt` captions) on the server; `docs/deployment.md` section 19 says how and that a deploy leaves them alone.
- **Locally:** `php artisan migrate` (the migration is already applied here, from the mistake above), then `npm run build`.

**The manual phone test:** section 23 of `docs/manual-test-plan.md`. Do this first: **scan the demo's QR code with a real phone, play the four questions against Sara and Karim, and at the end tap Share result** (the share sheet is the one thing no automated test can open), once in Arabic and once in English. Then paste the landing page's live address into WhatsApp to see the link preview.

**Content:** a native read of the blocklist (add the Arabic and Arabizi words you know are worst, and any false positive), and the Arabic strings above.

---

## 6. What could be annoying in real play

1. **If the queue worker is down, the demo's bots never answer** (the question still reveals on time, but a demo of one person and two silent bots feels broken). Suggestion: let `GameEngine::tick()` (the host screen already calls it every 2 s) answer for any bot whose planned moment has passed, so the worker only makes it prompter.
2. **Five demos an hour per IP will bite a school, an office or a café sharing one address**, and the visitor sees only "try again in N minutes". Suggestion: raise it to 20, or count per IP **and** per host cookie.
3. **Someone with only a laptop has nobody to play on.** After 90 seconds the lobby says "Scan the code", but a visitor with no phone at hand is stuck. Suggestion: a "Join from this laptop" link in that banner that opens `/join?code=` in a new tab (not tried: the same browser would hold both the host cookie and a guest cookie).
4. **A blocklist is never finished**: short words are exact-only on purpose, so "bigdickenergy" (all lower case, no separators) gets through. Suggestion: log how often the filter refuses a name (a count, never the word) and review it with a native speaker every few weeks.
