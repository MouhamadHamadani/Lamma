# Lamma · لمّة — Phase 14.1 report: follow-ups to the demo phase

Written 2026-11-08. `CLAUDE.md` is gitignored, so its Phase 14.1 edits are local only. **Nothing has been pushed** (a push to `main` deploys). The two new migrations were applied to your **local** MySQL with `php artisan migrate` (additive, nothing emptied).

| Commit | What |
| --- | --- |
| `7e6410a` | 8. The guard against destructive artisan commands, `.env.testing`, `lamma:backup-local`, README |
| `d9ec584` | 1. The big screen stays the host in a browser that also plays, and **Join from this laptop** |
| `f6b3533` | 3 + 4. Number questions get +5 s; the reveal is 8 s when there is something to read (one commit: both rewrote the same engine lines) |
| `27f42ec` | 2. **Next: double points** on the second-to-last reveal |
| `be76c28` | 5. Demo bots answer from `tick()`: no queue worker needed |
| `f514eec` | 6. Demo limits: 30 per IP and 5 per host cookie per hour |
| `b8bec68` | 7. Nickname list gaps, `v` as `u`, the refusal counter |
| (this commit) | HANDOFF section 14, manual test plan section 24, deployment notes, this report |

The order is the order I did them in (the guard first, because last phase's accident is what it prevents).

---

## 1. What was built, per section, and where I deviated

### 8. The guard against destructive artisan commands (`7e6410a`)

`AppServiceProvider::configureDestructiveCommands()` calls `DB::prohibitDestructiveCommands()` in production, and in `local` unless `LAMMA_ALLOW_DESTRUCTIVE=true` (`lamma.allow_destructive`). `.env.testing` is committed: SQLite in memory, array cache and session, sync queue, null broadcaster, dummy keys, the flag `false`; so `--env=testing` can never reach your MySQL. The tests still run (`APP_ENV=testing` is neither production nor local). `php artisan lamma:backup-local` writes a mysqldump (`--path`, `--connection`, `MYSQLDUMP_PATH`, a WAMP install found next to PHP, else the PATH). `README.md` (new) explains it; `CLAUDE.md` "Gotchas" says it. 18 tests (`DestructiveGuardTest`), including a child process that runs `config:show database.default --env=testing`.

**A consequence you should know about:** Laravel's list is `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback` and `db:wipe`. `migrate:rollback` is on it, and `docs/deployment.md` section 13 told you to use `migrate:rollback --step=1` to undo a bad migration on the server. **That now refuses in production, with no flag that lifts it** (the flag is for your own machine). I checked the three environments from the command line (production and local: prohibited, staging: allowed) and wrote the break-glass command into section 13: `APP_ENV=staging php artisan migrate:rollback --step=1 --force`, named on the command line and never in `.env`. If you would rather keep `rollback` allowed in production, it is one line in the provider; I did not, because a rollback drops tables.

### 1. The big screen stays the host (`d9ec584`)

**Reproduced first**, as asked. A feature test at the real `/broadcasting/auth` route: with a player row for the browser's guest cookie, the host screen's request came back as `player:1`, nickname "Sara", **`is_host: false`**: the `player` guard is tried first and wins. Then live, with Reverb and two tabs of one browser (the host screen and a player tab): the channel counted **one member** for the two tabs (Pusher presence members are keyed by user id), so closing the player tab never fired `leaving`, the player's `left_at` stayed null, and the lobby kept a "connected" player that never became ready ("0 of 1 ready", Start locked). So the host screen was being taken for a player, and a player who left could not be told from one who stayed.

**The fix.** The big screen's Echo sends `X-Lamma-Role: host` (`<html data-realtime-role="host">` is set only by the host lobby's layout, `resources/js/echo.js` reads it). The channel's guards are now `['host', 'player', 'web', 'demo']`. The new `host` guard (`RoomPresence::hostScreenFor`) believes the header **only** when the request really is the room's host: the logged-in `web` user is `rooms.host_id`, or the demo cookie matches `host_token_hash`. A marker from anybody else (a phone, a stranger, another room's host, a wrong value, a body field) authorizes nothing and is ignored; the old guards decide as before. The host's own phone never sends it, so it stays a normal player (the Phase 12 rule, tested again). *Deviation:* you described the check as living in `RoomPresence::memberFor`; I put it in a request guard instead, because Laravel's channel auth picks the first guard that returns a user, so the decision had to be made before `memberFor` runs. `memberFor` is unchanged.

**Join from this laptop.** The demo lobby's "Waiting for you? Scan the code" row (after 90 s with nobody real) has a link **Join from this laptop**, opening the join page in a new tab (`rel="noopener"`).

**A thing the browser test taught me about the "Reconnecting…" card.** With Reverb not running, the card (`fixed inset-0`, `lammaConnection`) covers the whole page about ten seconds after it loads, and a *real* mouse click on a button is then intercepted by it: Playwright waits for minutes and finally reports "Target crashed". My whole-demo test passed while a preview Reverb of mine happened to be running, and failed after I stopped it, which is how I found it (I bisected it back through the commits first: the same code failed too). The other browser files click within a few seconds of a page load, or through the DOM; the slow demo test now clicks Start and Ready through the DOM (`domClick`), with a comment saying why. It is the card working as designed (a host whose socket is down should not tap Start), but a browser test that is slow must not use a real click.

Tests: `Feature/Broadcasting/HostScreenRoleTest` (the real route: the host screen vs a player tab, real and demo room; the header grants nothing to a phone, a logged-in player, a stranger, another room, wrong values; the host's own phone; the markup carries the role only on `/host/{code}`; Start works; a leaving player is marked gone), `Browser/SameBrowserTest` (two tabs in one browser context, a real room and **a whole demo in one browser**: banner, link, join, ready, start, four questions, podium), additions to `DemoTest`.

### 3 + 4. Number time and reveal time (`f6b3533`)

- `lamma.number_question_extra_seconds` (5): `GameEngine::secondsFor($room, $roomQuestion)`; `ends_at = started_at + seconds_per_question + 5` for a closest-number question. `RoomQuestion::durationSeconds()` feeds `QuestionStarted.seconds`, the host ring and the phone bar, so they run the longer time. The early reveal (everybody guessed), the 500 ms grace and scoring are untouched.
- `GameEngine::REVEAL_SECONDS` is gone; `GameEngine::revealSecondsFor($roomQuestion)` gives `lamma.reveal_seconds` (5), or `lamma.reveal_seconds_long` (8) for a closest-number question **or any question with a fun fact** (`Question::hasFact()`: in either language). The `AdvanceQuestion` job, `advance()` (and so the Vote batch-boundary pause), the host's tick, and its "Next question in N s" bar all ask that one method. The Vote vote-result beat (3 s) is not a reveal and is unchanged. Next question still skips.
- 40 tests in `QuestionTimingTest`: the boundaries to the millisecond (deadline, last millisecond of the grace, one after), early reveal, scoring, Classic and Vote (a number question in the middle of a batch, at a batch end, and the last of the whole game), 5 vs 8 s for all six type/fact combinations and facts in one language, the job, the tick and the host's Next, config overrides, host and phone markup. One older test (`ClosestNumberTest`'s timing case) assumed 20 s for a number question and now says 25.
- *Deviation:* sections 3 and 4 are one commit, because they rewrote the same lines of `GameEngine`.

### 2. "Next: double points" (`27f42ec`)

`<x-lamma.double-next>` (gallery): a sun-tinted pill with the x2 sticker and **Next: double points**. `ScoringService::doublesNext($room, $question)` is the rule: setting on, at least two questions, `position == totalQuestions() - 1`, which is **the last question of the whole game in Vote mode too** (a batch end that is not second-to-last gets nothing). `GameState::$doublesNext` carries it. Big screen: at the end of the "N of M got it right" row; phone: in place of "Next question coming up…" after a right answer, a wrong one and "Time's up"; both live regions end with the words. Shown in a demo; never with the setting off or in a one-question game. 21 tests (`DoubleNextTest`: the rule table, phone, big screen in all three languages, Vote, demo) and real-browser checks (`Browser/ScoringTest`): no scrolling at 1280x720 and 1920x1080 (eight players, number question, fun fact) and at **360x640** in English and Arabic after a "time's up" and after the closest guess.

Deviations: (a) on the big screen in *Both* the pill shows English only, like the other labels of that footer ("Next question in", "Scoreboard"): with Arabic as a second line it wrapped to two lines and touched the bottom edge at 1280x720; the x2 sticker needs no translation. (b) The 360x640 phone reveal with a number question, a fun fact and the closest-guess line scrolled by 8 px **even after** the pill replaced a text line, so a phone under 700 px tall now gets a slightly tighter reveal (gap, headline 44 px, +N chip 56 px, card padding, bottom padding); taller phones are unchanged.

### 5. Demo bots without the worker (`be76c28`)

`Bots::plan()` now also stores each plan in a new `bot_plans` table (room, player, position, `answer_at`, `correct`, `answered_at`; removed with the room). `GameEngine::tick()` (the host screen's 2-second poll) calls `Bots::answerDue()` for a demo room, which answers for every bot whose moment has passed; the delayed `BotAnswer` job still exists and, with the worker running, answers first. Both end in `Bots::answer()` → `submitAnswer`, whose one-answer-per-player rule and room lock mean a job and a tick that meet answer once. Only demo rooms and `is_bot` players; a tick that arrives after the deadline and its grace lets the bot go without an answer. Without the worker a bot answers up to two seconds after its moment. 16 tests (`DemoBotsTickTest`): the plan rows and their link to the job; nothing before a bot's moment, the right bot at its moment; **a whole demo to the end with every queued job left unrun**; job and tick in both orders and repeated; the first verdict wins; never a person, never a non-demo room, never another position or after the reveal; nothing in `question_seen` for a bot; plus a browser test (`DemoGameTest`) that plays a whole demo with `Queue::fake()` and checks all eight bot answers arrived.

### 6. Demo limits (`f514eec`)

`lamma.demo.per_ip_per_hour` (30) and `lamma.demo.per_cookie_per_hour` (5) replace `rooms_per_ip_per_hour`. Both use `RateLimiter`, only a start that succeeds counts, a browser with no cookie is counted by address and is given a cookie by that start (which counts), and either refusal gives the same "Please try again in N minutes" message with the **longer** of the two waits. Tests cover each limit alone, the cookie limit across addresses, another cookie on the same address, no cookie, the reset after an hour, a refusal not stretching the wait, and the settings. `docs/deployment.md` section 19 says it (and that behind a proxy `TrustProxies` must give the real client address, or the 30 is shared by everybody).

### 7. Nickname list (`b8bec68`)

- **Added.** `contains` (long, unmistakable): `khawal`, `5awal`, `nayyek`, `dayous`, `dayyous`, `metnayek`, `mitnayek`. `exact` (short or ambiguous, whole name or one of its words): `zeb`, `zob`, `teez`, `tiz`, `tez`, `5wal`, `nayek`, `neek`, `dayus`, `louti`, `lawati`, `lawat`, `ersh`, and `3ars` with the other vowels: `3ers`, `3irs`, `3ors`, `3urs`, `3erse`, `3arse`, `3ersh`, `3arsh`. Placed by my reading of length and ambiguity; **please look at the ones below.**
- **`FvCk`:** `v` is read as `u` in the leet reading only (`FvCk`, `Cvnt` are caught); the Arabizi reading never touches `v`, and the tests allow it (Vera, Ivan, Slava, Victor, Evelyn, Calvin, all 320 fixture names and every Faker first name stay allowed).
- **Fixture:** `tests/Fixtures/innocent-names.php`: **320** common Arab and Lebanese names (176 Latin, 144 Arabic script), including the ones that sit next to the new words (Nayef, Khawla, Khawlani, Dayana, Dayan, Louay, Lotfi, Lawand, Tiziana, Tezcan, Zeba, Ershad, Ersan, Metn, Mitri, عرسال…), each checked as written, in capitals, with a number after, with a space and a number, and with a number before; plus the 53 inline names and every Faker first name. 213 tests in `NicknameFilterTest`.
- **SECTARIAN AND POLITICAL SLURS:** an empty, documented section in each list and in the file header. **I put nothing in it.** I could not be certain which terms are slurs everybody understands as slurs and which are also names, sects or political positions, and blocking those would lock real people out. This one is for you or a native speaker.
- **«نيك»** stays blocked (it is a rude word and also "Nick" in Arabic). Accepted, as you said; a test pins it (and that «نيكول» and "Nick" are fine).
- **Counter:** `nickname_refusals` (a per-day COUNT, no word, no name, no room, no address) is written when a join or a rename is refused (not when a stored name is merely shown as "Player"), and the dashboard's stats row with Categories, Questions, Users and Rooms has a fifth figure, **Nicknames refused today** with **N in the last 7 days** under it (a rolling week, so it never resets on a Monday). 7 tests (`NicknameRefusalsTest`).

**Words that deserve your eye** (all `exact`, so only the whole name is refused): `zeb` and `tez`/`tiz` are also short real names or nicknames; `nayek` is a common Bengali surname; `lawati` is a real Omani family name (اللواتي); `3ars` (already listed) and `3ors` are also how people type عرس, "wedding". I blocked them because you listed them; each is one line to remove.

---

## 2. Test results

| | Result |
| --- | --- |
| `composer test` (Pint + PHPStan + Pest, once, at the end) | Pint passed; PHPStan level 7: 0 errors; Pest **2,769 tests, 2,763 passed, 6 skipped, 108,714 assertions** (Phase 14 ended at 2,547). The 6 skips are tests that skip themselves when a prerequisite is absent (a Fortify feature or email verification that is switched off, bash, a git checkout); none of them is new. |
| New or changed tests this phase | `QuestionTimingTest` (40), `DoubleNextTest` (21), `DemoBotsTickTest` (16), `NicknameRefusalsTest` (7), `NicknameFilterTest` (+~80, now 213), `HostScreenRoleTest`, `DestructiveGuardTest` (18), additions to `DemoTest` (now 50), `ScoringScreensTest`, `ClosestNumberTest` |
| Browser (each file on its own, Playwright killed between files, **Reverb stopped**: the screens follow by polling) | **93 tests, 708 assertions, all passed**: HostScreensTest 14, FullGameTest 1, DemoGameTest 3 (one is new: a whole demo with every queued job left unrun), SameBrowserTest 2 (new), QuestionTypesTest 13, VoteModeTest 4, SmokeTest 44, ScoringTest 12 (two new cases for the pill: the big screen at 1280x720 and 1920x1080 in Both with eight players, a number question and a fun fact; a 360x640 phone in English, in Arabic and after the closest guess). Not run this phase: MotionTest, SoundTest, the selfie and photo files and the analytics and share-card files (nothing they cover changed; `MotionTest` is the file CLAUDE.md warns about on this laptop). |
| Production build | `npm run build` clean: `app.css` 77.9 kB (14.6 kB gzip), `app.js` 95.0 kB (28.5 kB gzip). The only JavaScript change is the role header in `echo.js`. |

---

## 3. New Arabic strings that need a native speaker

Two; both are also in `lang/en.json`:

| English | Arabic | Where |
| --- | --- | --- |
| Next: double points | التالي: نقاط مضاعفة | the pill on the second-to-last reveal (reuses «نقاط مضاعفة» from "Double points") |
| Join from this laptop | انضم من هذا الحاسوب | the demo lobby's link; «حاسوب» is the formal word, a Lebanese host may say «لابتوب» |

The nickname message and the demo-limit message are unchanged. The empty "sectarian and political" section in `resources/blocklist/nicknames.php` also waits for a native speaker.

---

## 4. What needs you

- **Deploy:** nothing new to configure. `deploy.sh` runs the **two migrations** (`2026_11_10_090000_create_bot_plans_table`, `2026_11_10_090100_create_nickname_refusals_table`) and restarts the workers and Reverb (the channel auth changed). `docs/deployment.md` section 20 lists it. Do **not** put `LAMMA_ALLOW_DESTRUCTIVE` in the server's `.env` (it does nothing there, on purpose).
- **By hand** (`docs/manual-test-plan.md` section 24, about 15 minutes): the same-browser host screen plus a join tab **with Reverb running** (I did it live with Reverb in the built-in browser earlier in this phase, but the socket is yours to confirm on your laptop); the pill on a real phone and with a screen reader; a number question's longer ring; the 8-second reveal; **a demo with `queue:listen` stopped**; the demo limits; a few of the new nickname words; and `php artisan migrate:fresh` refusing.
- **Decide:** the words listed in section 1.7; whether `migrate:rollback` should stay blocked in production; the empty sectarian section.
- **Your local database:** I did not touch it beyond `php artisan migrate`. The preview servers I started are stopped.

---

## 5. Three things that will annoy people in real play (my reading of the screens, not yet seen with real players)

1. **The demo drags when the person is quick.** A bot answers anywhere from 25% to 85% of the question's time, and the question reveals as soon as the last of the three has answered, so someone who taps in two seconds waits up to about 13 s (15 s demos) for Sara and Karim. Suggestion: plan the bots between 15% and 55% so a quick player waits at most 8 s.
2. **A wrongly refused name has no way out.** "Zeb", "Tez" or "Lawati" simply cannot join, and the message cannot say why, so they will try "Zeb2" (also refused) and leave. Suggestion: a one-line allow-list next to the block-list (`resources/blocklist/allowed.php`) and a "contact" hint on the repeated refusal, once the dashboard figure shows how often it happens.
3. **A host testing alone still needs the code in a second tab.** The same-browser case works now, but only the *demo* lobby offers **Join from this laptop**, after 90 seconds; a real lobby shows "Want to play too? Join from your phone with this code." Suggestion: the same link in the real lobby's line, so a host on one laptop does not have to type the code.
