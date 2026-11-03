# Lamma · لمّة — Phase 11 report: Vote mode

Written 2026-11-03. `CLAUDE.md` said "Phases 1-10 done" and the last commit was Phase 10's, so this is **Phase 11**, as the prompt guessed. `CLAUDE.md`
is gitignored, so its Phase 11 edits are local only.

| Commit | What |
| --- | --- |
| `4203eb6` | 1. Data model: `rooms.game_mode / vote_every / first_category_id`, `room_category_pool`, `category_vote_rounds`, `category_votes`, models, factories |
| `87cb1cd` | 2. Create room: Classic / Vote, the Vote setup and its checks, `RoomManager`, Play again, `CategoryVote` |
| `9ac5eb0` | 3. Engine: the first batch at the start, `drawBatch` for the next ones |
| `e1e92c3` | 4 + 5. Engine: the voting state (`castVote`, `closeVote`, `CloseVote` job) and the three events, one commit because each needs the other |
| `e837225` | A fix to an existing test found by the baseline run (see section 2) |
| `e1ef085` | 6 + 7. Host and phone vote screens (and a tighter Vote setup so create room does not scroll) |
| `5a5bcd3` | 8. Played categories in My games and the admin, admin Mode column / filter / figure, prune check |
| `3090889` | 9. The whole-game simulation, the Classic regression and the browser test |
| (this commit) | 10. Docs: this report, the manual test plan, HANDOFF section 10, sound-and-motion, and one small fix to the host vote screen (see the end of section 1, part 6) |

---

## 1. What was built, per section

### 1. Data model

- Migration `2026_11_03_090000_add_vote_mode` (reversible; tested down and up): `rooms.game_mode` (string, default `classic`, enum `App\Enums\GameMode`),
  `rooms.vote_every` (nullable tinyint), `rooms.first_category_id` (nullable FK, null on delete); `room_category_pool` (room, category, primary key on both);
  `category_vote_rounds` (room, `round`, `option_ids` JSON in display order, `ends_at` and `closed_at` `timestamp(3)`, `winner_category_id`, `outcome`
  = `App\Enums\VoteOutcome` votes / tie / no_votes / only_option, unique room + round); `category_votes` (room, round, room_player, category, unique room + round +
  player, index room + round). Everything cascades with the room.
- **The voting state** is an open round (`closed_at` null), the way the countdown is `countdown_ends_at` and the reveal is `revealed_at`. The deadline lives on the
  round (`ends_at`), not on `rooms` (deviation 1).
- Models `CategoryVoteRound`, `CategoryVote` (millisecond date format), factories, `RoomFactory::vote()`, relations on `Room` (`pool()`, `voteRounds()`,
  `firstCategory()`), plus `isVote()`, `totalQuestions()`, `playedCategories()`, `categoryIds()`, `voteSettings()`. `App\Game\VoteSettings` is the setup value object.
- `room_questions` gets nothing: each row's category is its question's.

### 2. Create room

- **Classic / Vote** segmented control beside the page title (deviation 2), so a Classic setup is exactly as tall as before (still no scroll at 1280x720).
- Vote replaces the category grid with **First category** (a row of category chips, single choice; it joins the pool and is locked there), **Categories to vote
  on** (the top-level category tiles; no subcategories) and **Vote every 3 / 5 / 10** (default 5), with the helper line "The game starts with this category. After
  every few questions, the players vote on the category of the next ones."
- Checks (`CreateRoom::voteProblem`, `CategoryVote::shortfalls` / `poolTotal`, again in `RoomManager::create`): a first category, at least 3 in the pool with the
  first, vote every smaller than the number of questions, and every pool category able to fill a batch at the chosen difficulty. A short category shows its count on
  its tile and in "Not enough questions for a batch of 5 in: Science (3)." and creation is blocked. The pool must also hold the whole game (deviation 3).
- Summary card: *Mode: Vote · 3 categories*, *First category*, *Votes: Every 5 questions · 3 votes*, then the usual rows.
- Play again copies the mode, first category, pool and vote every (`RoomManager::playAgain` passes `Room::voteSettings()`).

### 3. Engine: batches

- Classic: unchanged (everything drawn at the start).
- Vote: `GameEngine::start()` draws only the first batch (vote every, or all of a shorter game) from the first category, positions 1..n, option order shuffled once.
- `CategoryVote::drawBatch($room, $category, $count)` (the prompt's `drawNextBatch`) appends the winner's batch with the next positions in the same transaction as the
  close, through `QuestionPicker::pickFrom()`: the existing ranking (unseen first, then not in the host's last 3 rooms, then oldest seen), all of the category's
  subcategories, the difficulty, fully translated, never a question already in the room. If the winner runs short it fills from the other pool categories and logs a
  warning. `advance()` finishes the game if, all the same, nothing is left (only possible when content is deleted mid-game).
- The total shown everywhere (progress dots, "Question 6 of 20", `QuestionStarted.total`) is the host's total (`Room::totalQuestions()`).

### 4. Engine: the vote

- After the reveal pause of a batch's last question (or the host's **Next question**), `advance()` opens the vote instead of the next question
  (`Transition::Voting`): up to 3 random pool categories not played in this cycle, fewer when fewer are left, a new cycle without the one just played when none is
  left. Deadline now + `lamma.vote_seconds`, `VoteStarted`, `CloseVote` queued for the deadline + 500 ms. With **one option** there is no vote: the round is
  recorded closed (`only_option`), its batch drawn, and the next question opens as usual.
- `castVote($player, $category)`: the room row lock (like `submitAnswer`), only while open and until the deadline + 500 ms, only an offered category, once (the
  unique index settles a double tap), never changed. `PlayerVoted` carries counts per option only. When every connected player has voted it calls `closeVote()`.
- `closeVote($room, $round, $force)`: `Cache::lock` + row lock like every transition, `Already` the second time (the job twice, a double Skip vote, the job after Skip
  vote). It counts, breaks a tie (or nobody voting) at random and records it, draws the batch, sends `VoteResult`, and queues `AdvanceQuestion` for
  `lamma.vote_result_seconds` later; `advance()` then opens the next question through the same code path as any other (no countdown between questions, as today).
- `tick()` closes a due vote, so the host screen moves the game without a queue worker. **Skip vote** on the host is `closeVote(force: true)`.
- Race safety: castVote and closeVote both lock the room row, so a vote meeting the close is counted before it or refused after it; tested both ways.

### 5. Events

`VoteStarted` (round, after_position, the offered categories {id, name ar/en, tint, icon} in display order, ends_at; no counts), `PlayerVoted` (round, counts per
category, voted, expected), `VoteResult` (winner_id, counts, voters per category {id, nickname, avatar_url only when the room has photos}, was_tie, no_votes,
closed_at). All `ShouldBroadcastNow` on `presence-room.{code}` with the server time; none carries a token or an email (tested on the JSON).

### 6. Host screen

- **Vote:** "Choose the next category" (+ «اختر الفئة التالية» in Both), the timer ring (screen readers hear only 5 s), three tall answer tiles side by side
  (colour + shape + name, the Arabic name in Both) with a count chip that pops on every change, the players' avatars with a check once they voted, "2 of 3 voted", and
  **Skip vote**. The header chip says "Next category"; the progress dots carry a small upright marker after each batch, coral while that vote is on.
- **Result (3 s):** the winner keeps the correct-answer treatment (sticker-lg, ring, a badge that says **Winner**) and is enlarged to 105%, the others fade to 35%,
  mini avatars on each tile show who voted for it. A tie says **Tie, picking randomly** (+ Arabic) and the highlight hops between the tied tiles for about 1.2 s before it
  lands on the winner with the reveal sound, a pop and a confetti burst; nobody voting says **Nobody voted, picking randomly** and hops across all of them. Reduced
  motion: no hop, it lands at once.
- Nothing scrolls at 1280x720 or 1920x1080 (browser test).
- No per-second server calls: the ring and the bar run in the browser (`wire:ignore`), the screen asks the server once at the due moment and the 2 s poll is the
  fallback. Livewire re-runs `x-init` on each poll when its text changes, which re-armed that one timer every 2 s (the question screen still does this, harmlessly:
  the extra ticks are no-ops); the vote screen keys it with `$store.sound.once`, so it fires once per vote and once per result.

### 7. Phone screen

- **Vote:** the timer bar, "Choose the next category", three stacked tiles in the player's own language; one tap locks it (lock-in sound if on, the tap buzz).
- **Voted:** "Vote locked in", the chosen tile big and tilted, the pulsing dots, "2 of 3 voted". Nobody else's vote is ever drawn on a phone.
- **Result:** the winner as the big tile, the tie / no-votes line, **Your pick won!** for those who picked it, or **You didn't vote**. No score, no rank.
- Announcements: "Vote started", "Vote locked in", "<Category> won"; the timer speaks at 5 s only.

### 8. Reconnect, results, admin, prune

- Both screens are built from the database (`GameView::vote()` → `VoteState`), so a reload or a reconnect lands on the vote with the same tiles in the same order,
  the time left, the counts (host), the player's own vote (phone), or the result. Tested for host and phone, and a disconnected player who comes back can still vote.
- **My games** and the admin room page now read `Room::categoryIds()`: the categories actually played in Vote mode (the first and the winners), never the pool. The
  results screens list no categories, so they needed nothing. The lobby chip says "Vote · starts with <first>".
- **Admin:** a Mode column and filter on Rooms, the mode, pool and vote every on a room's page, "N in Vote mode" on the dashboard's Rooms card. The most-played chart
  and the question stats already counted asked `room_questions`, so they count what a Vote game played; now tested.
- **lamma:prune:** Vote rooms need nothing special: an old lobby is closed, a game in a vote is left like any game in progress, and an old one loses its photos; tested.

### 9. Tests

New: `VoteSchemaTest` (6), `CreateRoomVoteTest` (19), `VoteBatchTest` (11), `VoteTest` (20), `VotePayloadTest` (6), `VoteScreensTest` (17), `VoteResultsTest` (7),
`VoteSimulationTest` (2: a 20-question Vote game every 5 driven by the queue and the clock with a tie, a dropped phone, a reconnect mid-vote and a vote nobody answers,
up to `GameFinished`; and a Classic game that never votes), and the browser test `VoteModeTest` (3: one round with a host and a phone in separate contexts, and no
scrolling at 1280x720 and 1920x1080). The `/_components` gallery shows the vote tile (default and result) and the vote markers; its test passes. The existing
`SoundsTest` check of the timer's arguments was updated for the new `marks` argument; two `RoomManagerTest` cases build `RoomManager` through the container now.

### 10. Docs

`CLAUDE.md` (status, layout, the vote state in the state machine, a "Phase 11" section with the tables, events, config and helpers, the mode decisions), the manual
test plan's new section 20, HANDOFF section 10 "Vote mode", `docs/sound-and-motion.md` (the vote's cues and animations), and this report.

---

## 2. Before any change: the baseline

The first `composer test` overlapped my first edits and is not a baseline. I ran it again on a clean worktree of `a689dce` (the last Phase 10 commit):
**Pint passed, PHPStan 0 errors, Pest 1,711 tests: 1,704 passed, 6 skipped, 1 failed.** The failure was `DeployKitTest > docs/deployment.md only mentions files that
exist`: the deployment guide names `storage/app/private/livewire-tmp`, a folder Livewire creates at run time. It exists on a checkout that has been used, so the suite
was green here, but it fails on any fresh clone, which is what CI runs. Fixed in `e837225` (the test skips that run-time folder, like `storage/logs`).

---

## 3. Deviations from the prompt, and why

1. **The vote deadline is on the round, not on `rooms`.** The prompt listed a deadline column on `rooms` and the same deadline in the rounds table; one place is enough,
   and the round row is what a reconnect reads anyway (`category_vote_rounds.ends_at`, `timestamp(3)`).
2. **The Classic / Vote switch sits beside the page title**, not inside the settings card: inside the card it made the Classic setup taller and the page scrolled at
   1280x720, which "Classic shows today's screen unchanged" rules out. On a short laptop screen it is pinned to the bottom end of the title row so it adds no height.
   The Vote setup is also compacted there (the first-category chips, their label and Vote every on one line; summary rows a little closer; the line under the button
   hidden) so it does not scroll either.
3. **One more create-room check: the pool must hold the whole game.** The per-category check (each can fill a batch) is not enough on its own: with a small pool the
   same category comes back in a new cycle and could run dry, and the prompt also asks that a game never ends with fewer questions than its total.
4. **A Vote room keeps `settings.categoryIds` empty**; its categories are the pool table (no second copy that could disagree).
5. **`PlayerVoted` says `voted` and `expected`**, like `PlayerAnswered`: *expected* is the connected players plus anyone who voted and then dropped, so "2 of 3 voted"
   never goes backwards when a voter loses signal.
6. **A rejected vote is an `AnswerRejected`** (closed, late, already_answered, invalid_option): the same reasons as an answer, so no second exception class.
7. **A skipped vote (one option) is still a round** (`outcome = only_option`, no deadline), so the cycle rule ("not played yet") and the admin see every batch's
   category. No vote screen is shown for it.
8. **Nobody voting also gets the hop**, across all the tiles: the prompt asked for the hop on a tie; a random pick among all of them is the same moment.
9. **Sections 4 and 5, and 6 and 7, are one commit each**: the engine and its events need each other, and both screens share the vote components.
10. **The phone shows the tie / no-votes line too** ("Tie, picking randomly"), not only the winner: a player whose pick lost a tie otherwise has no idea why.

Also unspecified and chosen the simple way: the host's tick after a vote uses the same 2 s poll and due-moment timer as a question; the vote timer ticks like a
question's (the last 5 s, `time-up` at 0); a vote's results are not saved to My games (only the categories played).

---

## 4. Test results

| Check | Result |
| --- | --- |
| Baseline (clean worktree of `a689dce`) | Pint passed, PHPStan 0 errors, Pest 1,711 tests: 1,704 passed, 6 skipped, 1 failed (the fresh-clone folder check in section 2, fixed) |
| `composer test` at the end | **Pint passed, PHPStan level 7: 0 errors (no baseline entries, no ignores), Pest 1,799 tests: 1,793 passed, 6 skipped (the known email-verification skips), 96,603 assertions**, 558 s |
| New feature tests | 88 tests in 8 files (counts in section 1, part 9) |
| Browser tests | `VoteModeTest` 3 tests, 38 assertions, passed; the whole browser suite: each file run on its own: **110 of 111 tests pass**. `AnalyticsTest` 7, `FullGameTest` 1, `HostScreensTest` 14, `PhotoScreensTest` 10, `SelfieTest` 10, `SmokeTest` 40, `SoundTest` 4, `SubcategoriesTest` 10, `VoteModeTest` 3, `MotionTest` 11 of 12. The one that fails, `MotionTest > plays the expected sounds in order…`, fails the same way on the untouched Phase 10 code (a clean worktree of `a689dce`): headless Chromium reports **Target crashed** when the test really clicks the phone's sound button, so it is this machine, not Phase 11 (see section 4 note). `FullGameTest` hung once in the first pass and passed on its own after |
| Production build (`npm run build`) | builds without errors or warnings. `app.js` 90,711 B raw / 26,724 B gzip (`gzip -9`), Phase 10 ended at about 89,950 / 26,450: **+0.8 KB raw, +0.3 KB gzip**. `app.css` 70,315 B raw / 13,396 B gzip (Phase 10: 68,472 / 13,062) |
| Caught by the browser run | `SubcategoriesTest` found that the Classic setup with Sports expanded scrolled by 14 px at 1280x720: the mode switch (53 px) was taller than the title beside it (38 px). On a short laptop screen it is now pinned to the row's bottom end, so the row is as tall as before; Classic, Classic with Sports open and Vote are all exactly 720 px high at 1280x720 |
| Seen by eye | the phone vote, the locked vote, the host vote with live counts over Reverb, the tie's hop landing on one of the two tied tiles, the result, and the next question from the winner, in a local game with Reverb running and no queue worker (the host's tick moved it); the create-room Vote setup at 1280x720 |

Note on the browser suite: the whole suite in one run (`composer test:browser`) did not finish within 20 minutes here, because a crashed or failing Playwright page
hangs the run on Windows instead of failing it (known from Phase 7). Run per file (`vendor/bin/pest tests/Browser/<File>.php`) the results are the ones above. Worth a
look on a machine with sound hardware or in CI: if `MotionTest`'s sound test passes there, the crash is local to this laptop's audio setup.

---

## 5. New Arabic strings for a native review

| English | Arabic |
| --- | --- |
| Classic | كلاسيكي |
| Vote | تصويت |
| Game mode | نمط اللعب |
| First category | الفئة الأولى |
| The game starts with this category. After every few questions, the players vote on the category of the next ones. | تبدأ اللعبة بهذه الفئة، وبعد كل بضعة أسئلة يصوّت اللاعبون على فئة الأسئلة التالية. |
| Categories to vote on | فئات التصويت |
| Pick at least :count, the first one included | اختر :count على الأقل، ومنها الفئة الأولى |
| Vote every | التصويت كل |
| Not enough questions for a batch of :count in: :categories. | لا توجد أسئلة كافية لدفعة من :count في: :categories. |
| Take them out of the pool, or pick another difficulty. | أزلها من فئات التصويت، أو اختر صعوبة أخرى. |
| Pick the first category. | اختر الفئة الأولى. |
| Pick at least :count categories to vote between. | اختر :count فئات على الأقل للتصويت بينها. |
| Vote every :every questions needs a game longer than :every questions. | التصويت كل :every أسئلة يحتاج إلى لعبة أطول من :every أسئلة. |
| Some categories do not have enough questions. | بعض الفئات ليس فيها أسئلة كافية. |
| Mode | النمط |
| Votes | التصويت |
| Every :every questions · 1 vote | كل :every أسئلة · تصويت واحد |
| Every :every questions · :count votes | كل :every أسئلة · :count جولات تصويت |
| Pick the first category to continue. | اختر الفئة الأولى للمتابعة. |
| Vote started | بدأ التصويت |
| :category won | فازت :category |
| Next category | الفئة التالية |
| Choose the next category | اختر الفئة التالية |
| Tie, picking randomly | تعادل، نختار عشوائيًا |
| Nobody voted, picking randomly | لم يصوّت أحد، نختار عشوائيًا |
| The next category is | الفئة التالية هي |
| Category :letter: :text | الفئة :letter: :text |
| Winner | الفائزة |
| :voted of :total voted | صوّت :voted من :total |
| Skip vote | تخطّي التصويت |
| Vote locked in | تم تثبيت صوتك |
| Tap one. You can't change it after. | اضغط على واحدة. لا يمكنك تغييرها بعد ذلك. |
| The next category shows when time is up or everyone has voted. | تظهر الفئة التالية عند انتهاء الوقت أو عندما يصوّت الجميع. |
| You didn't vote | لم تصوّت |
| Your pick won! | فاز اختيارك! |
| Vote · :count categories | تصويت · :count فئات |
| Vote · starts with :category | تصويت · نبدأ بـ :category |

Worth a closer look: the gender of «فازت :category» (feminine, for فئة), «الفائزة» on the winner's badge (also feminine), «اختر» (masculine singular, as the
rest of the app uses) on the shared host screen, and «:count جولات تصويت».

---

## 6. What needs you

**Deploy** (the usual `deploy.sh` covers it): one new migration, `2026_11_03_090000_add_vote_mode` (adds three columns to `rooms`, three new tables; no data
changes, existing rooms stay Classic). `deploy.sh` runs `migrate --force`, rebuilds the assets and restarts the queue workers and Reverb, which is all this needs: the
new `CloseVote` job and the new events are code, so the workers must be restarted to know them (they are). No new environment variable (the two durations are in
`config/lamma.php`).

**The phone test:** section 20 of `docs/manual-test-plan.md` (one laptop on Both, Phone A a guest in Arabic, Phone B a guest in English, Phone C logged in): a normal
vote, a tie, nobody voting, a reconnect mid-vote, Skip vote, two of three always agreeing, a shorter last batch, one option left, and Play again keeping the mode.

---

## 7. What could be annoying in real play

1. **Ten seconds is short for a group that talks it over.** The vote often closes on the clock while people are still arguing. Suggestion: make it 15 s, or let the host
   add 10 s with one press during the vote.
2. **The same two friends decide every category in a small room.** Two out of three always win (it is the majority), and the third player never gets their topic.
   Suggestion: once every pool category has been played, give the player who has never won a vote a tie-break advantage.
3. **A pool of three gets predictable.** With three categories the second vote has one option left (no vote at all) and the third offers only the two already
   played. Suggestion: suggest a pool of at least 4 on the create-room screen (a hint, not a rule), or offer the least-played two plus one random instead.
