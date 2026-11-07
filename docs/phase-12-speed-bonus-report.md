# Lamma · لمّة — Phase 12 report: speed bonus, double final, 15 s, easy to hard, Vote tweaks, the host's phone

Written 2026-11-07. `CLAUDE.md` is gitignored, so its Phase 12 edits are local only. Nothing has been pushed: a push to `main` deploys.

| Commit | What |
| --- | --- |
| `a104dd8` | 1. `ScoringService`, the `Points` value object, the breakdown columns on `player_answers`, `RoomSettings::doubleFinal` |
| `92fac9d` | 2. The real +N, its breakdown and the x2 chip on the screens; browser `ScoringTest` |
| `1b12df9` | 3. 10 / 15 / 20 / 30 s with 15 the default, the double-final switch on create room, the summary card, the admin room page |
| `fc64b4a` | 4. Mixed climbs from easy to hard in each batch |
| `acce849` | 5. 15 s votes, the host's +10 s, the stale `CloseVote`, the hint at exactly 3 categories |
| `2d23813` | 6. The host's phone |
| `21e86a0` | HANDOFF section 11, manual test plan section 21, sound notes, two test fixes (section 3, items 8 and 9) |
| (this commit) | This report |

---

## 1. What was built, per section

### 1. ScoringService

- `pointsFor($correct, $answeredAt, $startedAt, $endsAt, $finalQuestion)` returns `App\Game\Points` (`base`, `speedBonus`, `multiplier`, `total`). `POINTS_PER_CORRECT_ANSWER` stays 100; new constants `SPEED_BONUS_MAX` 100, `FULL_SPEED_MS` 1000, `SPEED_BONUS_STEP` 5, `FINAL_QUESTION_MULTIPLIER` 2.
- The bonus is full up to 1000 ms, then falls in a straight line to 0 at the full duration, rounded to the nearest 5, from server times only. At or after `ends_at` (so inside the grace) it is 0 and the answer still scores the 100. An exact half (52.5) always rounds up: it is one division of two whole numbers, so float noise cannot change it.
- `isDoubled($room, $question)` is the one rule for "this question counts double": the setting on, and the position at or past `Room::totalQuestions()`, which is the host's total in Vote mode. The engine and `GameView` both ask it.
- `GameEngine::submitAnswer` stores `points` (still the total) plus `base_points`, `speed_bonus` and `multiplier` (nullable; reversible migration, tested down and up on SQLite). Points are decided when the answer is accepted and added at the reveal, as before.
- Checked for "100 per answer" assumptions: `PlayerStats` and `GameStats` count `is_correct`, never points, so the correct-answer rate and the admin widgets are right (a test pins it). `lang/` had no +100. The only other hits in `app/` and `resources/` were comments and the gallery's sample numbers, all changed.

### 2. Screens

- **Phone reveal, correct:** the real +N, then "100 + 65 speed", then on a doubled question the x2 chip with "final question". Wrong and time's up stay "+0" with no line.
- **`<x-lamma.double-chip>`** (new, in the gallery): on the host's timer ring (absolutely positioned, so the last question fits exactly as the others do), after "N of M got it right" on the host's reveal, and in the category row of the phone's question card. The live regions add "Double points" on the host's question and reveal and the phone's question and correct reveal.
- **Host scoreboard:** the +N count-up already read the real points, so it only needed checking.
- **Fit:** the browser test puts scores of 3,950 to 4,350 on a 360x640 phone (score chip, reveal, results rows) and 4-digit scores on the host from 1280x720 to 1920x1080: no sideways scroll on the phone, no scroll at all on the host.
- **A small addition:** the check circle on the reveal is 96 px under 700 px tall, so the new line does not push the correct-answer card off a short phone. At 360x640 the page is still 3 px taller than the screen on a normal question and 41 px on the doubled one. It scrolls; only the "coming up" line is affected.

### 3. Room settings

- `SECONDS_PER_QUESTION` [10, 15, 20, 30], default 15; `doubleFinal` default true, in the JSON as `double_final`, validated, saved, and copied by Play again with the rest of the settings. A room created earlier keeps its timer.
- Create room: the switch, and a **Last question** row on the summary card (*Double points* / *Normal points*). The settings grid is now three rows of two with the controls lined up on the bottom edge. On a short laptop screen the long label sits beside its switch. It does not scroll at 1280x720, Classic or Vote, English or Arabic (browser-tested). To fit the extra row, the Vote summary rows are 4 px tighter on a short laptop screen.
- The admin room page shows the setting too (one line, like Player photos).

### 4. Question order

`QuestionPicker::inPlayOrder()`: a Mixed batch is a stable sort of an already shuffled batch by difficulty, so it is easy, medium, hard and random inside each tier; a single difficulty stays shuffled. `pickFrom` applies it, and `CategoryVote::drawBatch` applies it again after filling a short batch from other categories. The option shuffle and the "unseen first" ranking are unchanged. Tests prove the tiers climb in Classic, restart in each Vote batch, hold through a short batch, are random inside a tier, and that a single-difficulty game stays random.

### 5. Vote tweaks

- `lamma.vote_seconds` 15.
- `GameEngine::extendVote($room, $round)` under the usual lock: moves `ends_at` by `CategoryVote::EXTEND_SECONDS`, sets `category_vote_rounds.extended_at`, broadcasts the new `VoteExtended`, queues a new `CloseVote`. A second press is `Already`; a press once it closed, or for another round, is `Stale`.
- `CloseVote` now carries the deadline it was queued for, and `closeVote(..., deadlineMs:)` is `Stale` when `ends_at` has moved, so the old job exits at once instead of retrying and failing.
- The timers are `wire:ignore`, so they carry a `wire:key` with the vote's length: when the deadline moves they are redrawn from it. A real-browser test watches both timers jump and keep counting.
- The hint "Pick 4 or more so every vote has a real choice." takes the existing hint's place at exactly 3 categories. Create stays on.

### 6. The host's phone

What happened before this phase:

- **Phone as a guest (logged out):** already worked.
- **Phone logged in as the host:** refused by `RoomJoiner` ("You're the host of this room").
- **Removing only that refusal would have broken it another way:** the phone and the big screen would be one presence member (the same user id), `memberFor` would return the host's member with no player id, the phone would never be marked connected, and the lobby would drop it after 30 s.
- **A guest phone that then logged in** could not save its score, because `ClaimGuestResults` skipped rooms the user hosts.

The fix, smallest I could find:

- `RoomJoiner` no longer refuses the host, and a host's row keeps both `user_id` and the device's guest token.
- The channel's guards are now `['player', 'web']`: a device with a row in the room is that player (`player:{id}`, its own member), and the big screen, whose device has no row, stays the host.
- `guestPlayerFor` skips a row another account has claimed when someone is logged in, which keeps the shared-device rule that the order change would otherwise have broken (test added).
- `ClaimGuestResults` no longer skips rooms the user hosts.
- The lobby has the "Want to play too?" line. The phone components have no host actions at all, and tests check their HTML for every host control.

---

## 2. Before any change: the baseline

`composer test` before the first edit: **Pint passed, PHPStan 0 errors, Pest 1,799 tests: 1,793 passed, 6 skipped, 96,603 assertions.** Nothing was broken.

---

## 3. Deviations from the prompt, and why

1. **`doubleFinal` went in with section 1,** not section 3: the scoring rule needs the field. The switch and validation are in section 3.
2. **The phone's "x2 final question" is the chip followed by the words,** so the chip on the phone's reveal and that line are one element rather than two.
3. **On the phone reveal the chip shows only for a correct answer.** The prompt asks for the chip on "the reveal" and also for wrong and time's up to stay unchanged; I took "unchanged" for those two. The host's reveal always shows it.
4. **A wrong answer stores base 0, bonus 0 and the question's multiplier** (2 on the last question), total 0. The prompt does not say; this keeps the column true to the question.
5. **`VoteExtended` is a new event** (the prompt only says the deadline moves), so phones redraw at once. The 4 s poll is the fallback.
6. **`EXTEND_SECONDS` is a constant, not config:** it never changes per environment.
7. **The events do not carry a "doubled" flag:** the screens render from the database, as everywhere else.
8. **`FullGameTest`'s plan changed.** With the last question doubled, Maya's one right answer on it caught up with the guest's two, which is the feature working. The guest now answers the last one right.
9. **A test constant of mine clashed with `PlayerLobbyTest`.** It was visible only as a PHP warning at load; I renamed it. No test helper or constant name is duplicated now.
10. **My mistake, fixed:** while comparing against the old code I removed a temporary worktree whose `node_modules` was a junction, and that deleted the real `node_modules`. I restored it with `npm ci` from the lockfile. The build gives identical asset hashes and the browser tests run. Nothing tracked by git was affected.

---

## 4. Test results

| Check | Result |
| --- | --- |
| Baseline | Pint passed, PHPStan 0 errors, Pest 1,799 tests: 1,793 passed, 6 skipped, 96,603 assertions |
| `composer test` at the end | **Pint passed, PHPStan level 7: 0 errors, Pest 1,889 tests: 1,883 passed, 6 skipped (the known email-verification skips), 99,733 assertions**, 503 s, no warnings |
| New feature test files | `ScoringTest` 27, `ScoringScreensTest` 14, `VoteExtendTest` 16, `HostPhoneTest` 12; additions to `QuestionPickerTest`, `CreateRoomTest`, `CreateRoomVoteTest`, `ChannelAuthTest`, `SchemaTest`, `RoomJoinerTest`, `ClaimGuestResultsTest` |
| Migrations | both new ones up, rolled back and up again on SQLite. **Not on MySQL:** the local MySQL was not running and I did not start it |
| Browser tests, per file | **118 of 119 pass:** `ScoringTest` 7, `VoteModeTest` 4, `HostScreensTest` 14, `FullGameTest` 1, `SmokeTest` 40, `SoundTest` 4, `SubcategoriesTest` 10, `AnalyticsTest` 7, `PhotoScreensTest` 10, `SelfieTest` 10, `MotionTest` 11 of 12 |
| The one failure | `MotionTest > plays the expected sounds`: "Target crashed" in headless Chromium, the crash `CLAUDE.md` records on this laptop since Phase 10. I did not rerun it on the old code this session |
| Production build | no errors. `app.js` 90,711 B raw / 26,724 B gzip, unchanged (no JavaScript in this phase). `app.css` 71,078 B raw / 13,496 B gzip: +0.8 KB raw, +0.1 KB gzip |
| Not checked | **A live game with Reverb and real phones.** The presence fix is tested at the real `/broadcasting/auth` route with the Reverb driver signing, and through the host lobby's presence handlers, but no browser test has Reverb, and a local live run needed MySQL. It is the first thing in the phone test below |

**On browser hangs:** `FullGameTest`, `SoundTest` and `MotionTest` first ended on my 500 s timeout. `FullGameTest` was the real stale expectation above. The rest was tooling: a leftover `playwright run-server` makes the next run hang, and the old code at `7d1ff8b` alternated pass, hang, pass, hang in exactly the same way. With that process killed between runs they pass. `MotionTest > and never scrolls sideways` hung once in 11 runs of this kind and then passed three times out of three, so treat it as flaky. `MotionTest` was run test by test, because the crash above hangs the whole file.

---

## 5. New Arabic strings for a native review

| English | Arabic |
| --- | --- |
| Double points | نقاط مضاعفة |
| :base + :bonus speed | :base + :bonus للسرعة |
| final question | السؤال الأخير |
| Double points on the last question | نقاط مضاعفة في السؤال الأخير |
| Last question | السؤال الأخير |
| Normal points | نقاط عادية |
| Pick :count or more so every vote has a real choice. | اختر :count أو أكثر ليكون في كل تصويت خيار حقيقي. |
| Vote extended | تم تمديد التصويت |
| Add :seconds seconds to the vote | أضف :seconds ثوانٍ إلى التصويت |
| Want to play too? Join from your phone with this code. | تريد اللعب أيضًا؟ انضم من هاتفك بهذا الرمز. |

Worth a closer look: «للسرعة» as a bare label after numbers; «ثوانٍ» is right for 10 and would need changing if the extension ever did; «تريد» and «انضم» are masculine singular, as the rest of the app uses, on a shared screen. The string "You're the host of this room..." is gone from both files.

---

## 6. What needs you

**Deploy** (`deploy.sh` covers all of it):

- Two new migrations: `2026_11_06_090000_add_points_breakdown_to_player_answers` and `2026_11_06_090100_add_extended_at_to_category_vote_rounds`. Both only add nullable columns, with no data migration; old games keep their points.
- The queue workers and Reverb must restart, which `deploy.sh` does: `CloseVote` changed and `VoteExtended` is new.
- No new environment values.
- A game running across the deploy: its earlier answers keep their points and it finishes under the new rules, including a doubled last question (a missing `double_final` reads as on). A vote open at that exact moment has an old `CloseVote` job that will fail, because it has no deadline field; the host screen's tick still closes the vote.

**Locally:** run `php artisan migrate` when MySQL is back on.

**The phone test:** section 21 of `docs/manual-test-plan.md`. Do this one first: **your own phone, logged in as the host, joins your room, the row appears on the big screen without a reload, and it stays past 30 seconds.** That is the one thing no automated test here can run.

---

## 7. What could be annoying in real play

1. **A lucky guess can decide the game.** In a Mixed game the doubled last question is always from the hardest questions drawn, so few people know it. Suggestion: end a Mixed game on a medium question, or show "x2 next" on the reveal before it, so the swing feels fair.
2. **Mobile data costs points.** The clock stops when the answer reaches the server, so a slow connection can lose a bonus step or two to someone on the room's Wi-Fi. Suggestion: if people notice, raise `FULL_SPEED_MS` from 1000 to 1500.
3. **A host who plays is also running the screen.** Pressing Next question or Skip timer on the laptop while answering on the phone costs them speed points. Suggestion: tell them on the lobby line that the game moves on by itself, or let the phone's owner skip the reveal pause.
