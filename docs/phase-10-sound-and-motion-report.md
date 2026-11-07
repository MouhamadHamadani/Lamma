# Lamma · لمّة — Phase 10 report: sound, haptics and game-feel motion

Written 2026-11-01. This is the prompt you called "Phase 8 — sound and motion". The repo was already at "Phases 1-9 done" (Phase 8 = no repeated
questions, subcategories, 1,200+ questions, Google Analytics; Phase 9 = player selfies), so it is recorded as **Phase 10** everywhere (`CLAUDE.md`,
this file, the commit messages say `feat(sound)` / `feat(motion)` without a number, like the repo's other commits).

Repo state: `main`, five commits on top of `cf51bba`, working tree clean. `CLAUDE.md` is gitignored, so its Phase 10 edits are local only.

| Commit | What |
| --- | --- |
| `1320990` | `feat(sound)`: the sound manager, toggles, haptics toggle, keyframes, `fx.js`, `confetti.js`, `countdown` and `streak-chip` components |
| `ca9f2ae` | `feat(motion)`: host sound and motion, the server-timed countdown (migration, engine, job, event) |
| `050c49a` | `feat(motion)`: phone sound, haptics and motion, streak chip, `rank_change` and `streak` display data |
| `43bf415` | `test(motion)`: display-data and script tests, `docs/sound-and-motion.md` |
| `a689dce` | `test(motion)`: real-browser `MotionTest`, manual test plan section 19, confetti colour caching |

---

## 1. Results at a glance

| Check | Result |
| --- | --- |
| Baseline before any change | Pint passed, PHPStan 0 errors, Pest 1,618 tests (see note 1 in section 5) |
| `composer test` at the end | **Pint passed, PHPStan level 7: 0 errors (no baseline entries), Pest 1,711 tests: 1,705 passed, 6 skipped (the known email-verification skips), 96,151 assertions**, 477 s |
| Whole browser suite (`pest tests/Browser`) | 108 tests, 955 assertions, all passed (about 7 minutes) |
| New browser tests | `SoundTest` 4 tests; `MotionTest` 12 tests, 284 assertions |
| JS bundle (`app.js`) | 80,920 B raw / 23,099 B gzip (`gzip -9`) before; about 89,950 B raw / 26,450 B gzip after: **+9.0 KB raw, +3.4 KB gzipped** (budget 15 KB) |
| CSS bundle | 65,104 B raw before, 68,472 B after (13,062 B gzip after; the "before" gzip was not measured) |
| Frame time, host question screen | 4x CPU throttle, 1280x720, 8 players, headless Chromium: **60.1 fps**, mean 16.7 ms, worst frame 16.8 ms, 0 frames over 33 ms |
| Frame time, host reveal (confetti, ring pulse, row slide, count-up) | **59.5 to 60.3 fps** over two runs, worst frame 16.8 to 33.3 ms, 0 frames over 33.4 ms (one earlier run had a 100 ms frame; caching the confetti colours removed it) |

---

## 2. What was built, section by section

### Section 1: the sound manager (`resources/js/sound.js`, Alpine store `$store.sound`)

- API: `play(name, {volume, rate})`, `mute()`, `unmute()`, `isMuted()`, `setVolume(v)`, `unlock()`, `buzz(kind)` (haptics), `once(key, run)` (see deviation 2), `configure({role, base, files})`, and `played` (the names that really started; what the tests read). It is a method `isMuted()`, not a getter.
- Web Audio API only, no library. One shared `AudioContext`, created on the first click, tap or key press (capture listeners on `click`, `touchend`, `keydown`, only when sound is on). `play()` before that is a silent no-op.
- A sound plays from `public/sounds/<name>.wav` when the toggle's file list names it (decoded once, cached as `AudioBuffer`s), otherwise from a synthesized sound. The four placeholder files keep their names; two of them have different names from their sound (`tick.wav` is `timer-tick`, `podium.wav` is `win`).
- 18 synthesized sounds, each under 1.2 s: `tap`, `lock-in`, `join`, `ready-on`, `ready-off`, `countdown-tick`, `countdown-go`, `question-start`, `timer-tick`, `time-up`, `correct`, `wrong`, `reveal`, `score-count`, `rank-up`, `streak`, `podium-bar`, `win`. At most 6 voices; master gain 0.5 behind a dynamics limiter. No looping music.
- Defaults: host ON after unlock, phone OFF, and both OFF when the OS asks for reduced motion unless the person chose. Saved per device in `localStorage` (`lamma:sound`, `lamma:volume`, `lamma:haptics`), every access in try/catch.
- Haptics: tap 10 ms, correct 30 ms, wrong 20-40-20 ms, feature-detected and only after the person has touched the page. Own toggle (`<x-lamma.haptics-toggle>`), hidden where the browser cannot vibrate.
- Switches: `<x-lamma.sound-toggle>` (`aria-pressed`, label "Sounds"/«الأصوات») on every host screen (lobby, countdown, question, reveal, results) and, with the vibration switch, on the phone lobby, game and results.
- A server-side `$this->dispatch('lamma-sound', name: ..., rate: ...)` plays a sound on the screen (used for the join sound, the ready chime and the answer tick).
- New components, all in the `/_components` gallery: `haptics-toggle`, `countdown`, `streak-chip` (plus a phone variant of `sound-toggle`). New icons: `bolt`, `vibrate`, `vibrate-off`, `arrow-up`.

### Section 2: host

- **Lobby:** a new player row pops in with a spring overshoot and the `join` sound; the room-code tiles drop in once, staggered 90 ms; when the last player taps Ready, "Start game" wobbles once and `ready-on` plays (once; not on every refresh; not when the host reloads an already-ready lobby).
- **Countdown:** a full-screen 3 · 2 · 1 · Go! sticker tile with a tick per number and a `countdown-go`, driven by the server's `countdown_ends_at`.
- **Question:** question-start sound, ring turns coral under 5 s, ticks each of the last 5 s, trembles in the last 3 s, ends with a gentle `time-up`; each answer ticks (`tap`) a little higher than the one before.
- **Reveal:** wrong tiles fade, the correct tile pops with a ring pulse and a burst of 18 confetti shapes, `reveal` swoosh, FLIP rows, the top scorer's "+N" counts up with `score-count` ticks, a row that climbed shows an up arrow and `rank-up` plays once.
- **Podium:** a thud as each bar lands (3rd, 2nd, 1st), the crown drops, then the winner's name scales in at 2.8 s with the `win` fanfare and 6 s of confetti rain (at most 120 pieces, stops when the tab is hidden).

### Countdown: the one change to game timing

- Migration `2026_11_01_090000_add_countdown_ends_at_to_rooms` adds `rooms.countdown_ends_at` `timestamp(3)`. `Room` reads and writes it through an `Attribute` (the model's own date format keeps whole seconds).
- `GameEngine::start()` no longer opens question 1. It sets `status = playing`, `started_at` and `countdown_ends_at = now + 3 s` (`GameEngine::COUNTDOWN_SECONDS`), broadcasts `GameStarted` (payload gains `countdown_ends_at`) and queues `StartQuestion` for that moment.
- `GameEngine::startQuestion($room, $force = false)` opens question 1 under the same `Cache::lock` + row lock as every other transition and returns a `Transition`: `Done`, `Already` (the job and the host's tick both ask; first one wins), `TooEarly` (before the deadline), `Stale` (room closed or not playing). `tick()` calls it when no question has started, so the host screen's timer (countdown end + 150 ms, and the 2 s poll) starts the game without a queue worker.
- `GameEngine::countdownEndsAt()` falls back to `started_at` for a game that started before this deploy (its first question is already open).
- The first question's own clock (`started_at`, `ends_at`) starts when it opens, not when the game starts.
- Tests: `tests/Pest.php` `startGame()` now calls `start()` and then `startQuestion($room->fresh(), force: true)`, so every existing game test keeps its clock. Nine new `GameEngineTest` cases cover the countdown (no question yet, payload, job queued with the right delay, no early start by job or tick, opens at the deadline with its own clock, idempotent, host-tick fallback, closed room, legacy game, the job's three paths). `CLAUDE.md`'s state-machine diagram now shows the countdown.

### Section 3: phone

- **Lobby:** avatar pops in; the big Ready button squishes on a tap, bursts a small teal confetti when it turns on, plays `ready-on` / `ready-off` and a tap haptic; the Join button buzzes.
- **Countdown:** the same overlay as the host, from `countdown_ends_at`.
- **Question:** answer tiles slide in staggered (from the reading side, mirrored in Arabic), a tap plays `lock-in` with a haptic, the chosen tile grows and settles into its tilt, the timer bar breathes under 5 s.
- **Reveal:** correct wipes the teal screen up, the check draws itself (SVG stroke), "+100" pops, small confetti burst, `correct` and a long buzz. Wrong or time's up stays cream (never red) with a soft one-time shake of the cross/clock, `wrong` and a short buzz.
- **Streak:** from 3 right answers in a row a sun chip with a bolt and the count sits next to "+100", with a `streak` sound. Display only: scoring stays a flat +100. The screen-reader line adds "3 in a row" once; no animation is ever announced.
- **Results:** the rank tile drops in with a bounce and tilt, first place gets `win`, a long buzz and a confetti burst, leaderboard rows slide in staggered, the player's own row swells once.

### Section 4: support

- **Confetti** (`confetti.js`): `lammaConfetti.burst(origin, count, {tone})` and `rain(ms)`. One fixed canvas, `pointer-events: none`, `aria-hidden`, removed after use, at most 120 pieces, no-op under reduced motion and while the tab is hidden, origins and drift mirrored in RTL, the four answer shapes in brand colours read once from the CSS tokens. `fx.js` has `lammaFx.pop/wobble/shake/squish` (Web Animations; a plain fade under reduced motion).
- **Display data:** `Scoreboard::ranking()` rows gain `rank_change` (rank before this round minus rank now; a climb is positive; 0 with no question). `Scoreboard::streaks()` counts right answers in a row from the stored answers. `QuestionRevealed.points[]` gains `streak`; `GameState::streakOf($player)` gives a phone its own. Neither exists before the reveal; no payload ever says which option is correct (the existing test still passes).
- **Docs:** `docs/sound-and-motion.md` lists every sound (name, when, file or synthesized, length) and every animation (trigger, duration) and how to swap a placeholder file.

### Section 5: verification

- `MotionTest` (browser): every host screen (lobby, countdown, question, reveal, podium, and an Arabic host) and every phone screen (lobby, countdown, question, answered, correct, wrong, time's up, results, a streak) at 390x844, 1280x720 and 1920x1080: no JavaScript error, no console output, no critical or serious axe problem, no sideways scroll on a phone, no scrolling at all on a host screen at laptop and TV sizes. A reduced-motion pass (no running transform animation, no confetti canvas, sound off to begin with) plus a control test that proves the detector does see movement without it. A whole game with the sound store recording what it is asked for and what really starts.
- `SoundTest` (browser): `play()` before unlock throws nothing and plays nothing; host starts on, phone starts off; mute persists across a reload; a phone's switch turns sound on and saves it.
- Feature tests: `SoundsTest` (store contract, every sound name asked for has a synthesized version under 1.2 s, toggles in en and ar with the right `aria-pressed`, store defined once and imported once, under 15 KB gzipped, no raw hex), `HostMotionTest` (20), `PlayerMotionTest` (31), `MotionScriptsTest` (11: confetti and fx contracts, keyframes move only transform properties and opacity, every animation behind `motion-safe:`, no animation library), `GamePayloadTest` (+5), `GameEngineTest` (+9).
- `docs/manual-test-plan.md` has a new section 19 "Sound and motion" for real phones (sound unlock, haptics, mute persistence, the iOS silent switch, the reduced-motion setting, a TV), and the Start step now says 3 · 2 · 1 · Go!.
- Live checks in a real browser against the dev server with Reverb running: the host and phone sound/haptic sequences (ready chime, three countdown ticks, go, question-start, five timer ticks, time-up, reveal; on the phone lock-in with a tap buzz, then correct with a long buzz and rising score-count ticks) and screenshots of the host reveal (ring pulse, confetti burst) and the podium (confetti rain).

---

## 3. Deviations from the prompt, and why

1. **Phase number.** Called Phase 10, not 8 (8 and 9 were already used). `CLAUDE.md` Status says "Phases 1-10 done" instead of "1-8".
2. **A pre-existing Livewire problem had to be fixed.** Livewire re-runs `x-init` and `x-data` on every refresh when the attribute text changed, and ours contained per-render values (`$dueInMs`, the server time). In a live recording `question-start` replayed every 2 s poll, four `timer-tick` sounds fired at the same instant, and every refresh also left a new timer interval running (the old timers leaked intervals). This was silent while sounds were off. Fix: the timers (`timer-ring`, `timer-bar`, the reveal's next-question countdown) and the countdown are now `wire:ignore`, and any cue that belongs to one moment uses `$store.sound.once(key, run)`. This touches existing components, beyond "presentation only", but sound could not be shipped without it.
3. **A new column, not just payload data.** The prompt allowed only extra display data in payloads, besides the countdown timing. The countdown needed a stored deadline that survives a reload and keeps milliseconds, and `rooms.started_at` keeps whole seconds, so `rooms.countdown_ends_at timestamp(3)` was added (a column, not a table). **Deploy needs `php artisan migrate`** (`deploy/deploy.sh` runs it).
4. **`rank_change` and `streak` are computed in `Scoreboard`**, next to the ranking, not in `GameEngine`. `streak` sits in each `QuestionRevealed.points[]` entry; `rank_change` is in each ranking row, so the `GameFinished` payload's rows carry `rank_change: 0` too.
5. **Phone header.** In a game the sound and vibration switches replace the logo (the question number and score chips need the room: 390 px does not fit logo + switches + both chips). On the other phone screens the logo wordmark hides under 380 px so the switches fit.
6. **The first countdown number lasts about half a second** on a normal connection, because the 3 seconds run from the Start click on the server and the screens only appear after the round trip. Not padded, to keep the 3 seconds you specified. A "3.5 s" server deadline would fix the feel; say so if you want it.
7. **Sound names reuse.** There is no separate "answered" sound: the answer tick is `tap` with a rising `rate`. The host's "everyone is ready" chime reuses `ready-on`. The sound toggle's file base is root-relative `/sounds` (not `asset()`), so a phone on the LAN address does not fetch from `APP_URL`'s `localhost`.
8. **`isMuted()` is a method**, since the other store members are verbs.
9. **Confetti is `confetti.js`, not an extension of `<x-lamma.confetti>`** (the prompt allowed either). The Blade component stays the still decorative scatter and its comment points to the helpers.
10. **Frame time was measured with a requestAnimationFrame recorder under CDP CPU throttling, not Playwright tracing.** It is headless Chromium with software rendering, so it is a stress test, not a TV measurement. There was no pre-change baseline measurement.
11. **Browser-test shape differs from the prompt in a few ways:** the whole-game test uses one host and one phone, not three windows; it cannot test the answer tick because browser tests run without Reverb (that tick is covered by Feature tests and the same server-to-browser event path was proven by the ready chime); host screens in a 390 px window are only checked for errors (they are for laptops and TVs and scroll sideways there); the countdown tile is excluded from axe because its number fades in every second and axe catches it half-faded (it is `aria-hidden` and its colour pairs are checked by `AccessibilityTest`); tests use a stretched 12 s countdown so the screen holds still.
12. **The game decision "Sounds are optional, off by default, host only" is replaced** (as the prompt decided): host on after unlock, phone off, per-device mute and volume, haptics on phones.
13. **Baseline note.** The first full `composer test` showed one `LocalizationTest` failure. I caused it by adding new `__()` keys to the new components while the suite was running; it passed as soon as the translations were added. The real baseline was green.
14. **Local environment, outside the repo:** a second preview configuration `laravel-8010` was added to `MultiplayerQuizGame/.claude/launch.json` (another project's dev server owns `localhost:8000` and shadows ours); the local MySQL database was migrated; my test rooms were deleted; the preview servers were stopped.

---

## 4. Not done, limits, and what needs a person

- **Nobody has heard the sounds or felt the haptics on a real device.** Needs ears and eyes: sound quality and loudness balance of the synthesized sounds, iPhone silent switch (Web Audio obeys it: no sound is normal), iPhone has no `navigator.vibrate` (the button is hidden), Android haptics, the OS reduced-motion setting on real phones, a laptop on a TV, an Arabic phone. All listed in `docs/manual-test-plan.md` section 19.
- **Arabic copy needs a native read.** New strings I wrote: «انطلقوا!» (Go!), ":count متتالية" (streak chip), «الاهتزاز» (Vibration), «تقدّم في الترتيب» (Moved up, screen-reader only), "صحيح، وحصلت على :points نقطة، :count إجابات متتالية" (announcement with a streak).
- **Confetti was not seen in the in-app browser pane** (it is hidden, so animation frames do not run there). It was checked through the canvas attributes in that pane and through screenshots and frame timings in headless Chromium.
- **Official sound files** are still the four placeholders (`question-start`, `tick`, `reveal`, `podium`). Dropping a WAV named after a sound (for example `correct.wav`) into `public/sounds/` is enough; delete the replaced file's line in `config/lamma.php` `placeholders` so `lamma:doctor` stops warning.
- **Unchanged limitation:** the game still advances only while the host screen is open or a queue worker runs; the countdown follows the same rule.

---

## 5. For the Claude Project memory

**Project:** Lamma · لمّة (`quiz-party/`, Laravel 13, Livewire 4, Alpine, Tailwind 4, Filament 5, Reverb). Status: Phases 1-10 done. Phase 10 = sound effects, phone haptics, game-feel motion and a 3 · 2 · 1 · Go! countdown. Presentation only; scoring is still a flat +100.

**Architecture facts that are not obvious from the code**
- Sound: Alpine store `$store.sound` (`resources/js/sound.js`). Web Audio, silent until a real click/tap unlocks it. File-or-synth: `public/sounds/<name>.wav` plays if the toggle lists it, otherwise a built-in synthesized sound (`SYNTH`). Host default on, phone default off, both off under reduced motion unless chosen; per-device `localStorage` keys `lamma:sound`, `lamma:volume`, `lamma:haptics`. `store.played` is what tests read. A Livewire `dispatch('lamma-sound', name: ...)` plays a sound.
- Livewire re-runs `x-init`/`x-data` on every refresh when the attribute text changed. Anything with a per-render value inside it must be `wire:ignore`, and one-moment cues must use `$store.sound.once(key, run)`, or sounds repeat every poll and timers leak.
- Countdown: `rooms.countdown_ends_at timestamp(3)` (Attribute on `Room`). `GameEngine::start()` no longer opens question 1; `GameEngine::startQuestion($room, $force)` does, at the deadline, called by the `StartQuestion` job or the host screen's tick (`tick()` handles it). Same lock rules and `Transition` results as the other transitions. `GameStarted` carries `countdown_ends_at`.
- Tests: `startGame()` in `tests/Pest.php` skips the countdown (forces question 1 open now); call `start()` alone to test the countdown. A Pest global helper name that exists in another test file crashes the whole run at load (grep `^function` first).
- Display data: `Scoreboard::ranking()` rows have `rank_change`; `Scoreboard::streaks()`; `QuestionRevealed.points[].streak`; `GameState::streakOf()`. Never before the reveal.
- Motion: keyframes in `app.css` ("Game feel"), transform and opacity only, always behind `motion-safe:` (a test enforces it); `fx.js` (Web Animations), `confetti.js` (canvas, max 120 pieces, off when hidden or reduced motion). No animation library.
- Browser tests: `tests/Browser/MotionTest.php`, `SoundTest.php`; `visit($url, ['reducedMotion' => 'reduce'])` emulates reduced motion; browser tests have no Reverb.

**Numbers:** 1,711 tests (1,705 passed, 6 skipped); 108 browser tests; `app.js` +9.0 KB raw / +3.4 KB gzip; host question screen 60 fps and reveal about 59.5 to 60 fps at 4x CPU throttle.

**Dev environment gotchas:** `localhost:8000` may be another project's server (use preview config `laravel-8010`, plus `reverb` on 8080; guest on `127.0.0.1`, host on `localhost`). The in-app browser pane is hidden (`document.hidden` true): no animation frames, no confetti, only the fronted tab takes real clicks, audio unlocks only on a real click. Host presence sync marks made-up players disconnected: reset `left_at`. Write multi-line patch scripts with the Write tool and run them with `python`; Bash heredocs choke on apostrophes and `\U`.

**Deviations to remember:** called Phase 10 not 8; a new column `rooms.countdown_ends_at` (deploy needs `php artisan migrate`); `rank_change`/`streak` live in `Scoreboard`, not `GameEngine`; phone in-game header swaps the logo for the sound/vibration switches; the first countdown digit shows about 0.5 s on a normal link; frame time measured with an rAF recorder, not Playwright tracing; Arabic strings for «انطلقوا!», «متتالية», «الاهتزاز» need a native read; real-device checks (sound quality, iOS silent switch, Android haptics, reduced motion, TV) still open (manual test plan section 19).

**Open items:** official sound files to replace the four placeholders; native-speaker review of the new Arabic strings; the real-device pass in section 19; optionally a 3.5 s server deadline so the first digit lasts a full second.
