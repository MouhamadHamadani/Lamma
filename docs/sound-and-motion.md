# Sound and motion

Every sound the game can make and every animation it plays, so the placeholder sounds can be swapped for the official ones. Presentation only: no
game rule, score or event depends on any of this. The code is `resources/js/sound.js` (the `$store.sound` store), `fx.js`, `confetti.js`,
`lamma.js` (timer, count-up, countdown) and the keyframes at the top of `resources/css/app.css`.

## How the sound works

- **One store, `$store.sound`:** `play(name, {volume, rate})`, `mute()` / `unmute()` / `isMuted()`, `setVolume(0..1)`, `unlock()`, `buzz(kind)` (haptics),
  and `once(key, run)` (a cue that belongs to one moment, such as "question 3 opened", fires once even if Livewire re-runs the markup).
- **Locked until a gesture.** Browsers only allow sound after a tap or a click, so `play()` is a silent no-op until the first tap, click or key press
  has unlocked the shared `AudioContext` (the host's Start game click, a phone's first tap on Join or Ready, or a click on a toggle).
- **Files first, synthesis as the fallback.** The speaker button lists the files in `public/sounds/*.wav` for the page. A sound whose file exists is
  fetched and decoded once and played from the buffer; any other sound (or a file that fails to load) is made by the browser with the Web Audio API.
  No new assets and no licences are needed. At most **6 sounds** play at once; the master gain is about 0.5 behind a limiter, so nothing clips.
- **Defaults.** A host's sound is **on** (once unlocked), a phone's is **off** (a room full of phones would beep). With the OS set to reduced motion
  both start **off** unless the person has turned it on. The choice, the volume and the vibration switch are remembered **per device** in
  `localStorage` (`lamma:sound`, `lamma:volume`, `lamma:haptics`). Switches: the speaker button on every host screen (lobby, countdown, question,
  reveal, results), and the speaker and vibration buttons on the phone lobby, game and results.
- **Haptics (phones that support `navigator.vibrate`):** tap 10 ms (a tap, a countdown number, Ready), right answer 30 ms, wrong answer 20-40-20 ms. Only
  after the person has touched the page; the button is hidden where the browser cannot vibrate (iPhones).
- **No looping music.** Nothing loops.

## Sounds

`File` = the file that plays when `public/sounds/<file>.wav` exists (the placeholders shipped today); `synth` = made by the browser. Lengths are
approximate. To use an official recording, drop a WAV with the **file name** in `public/sounds/`: the placeholders that exist today keep their names,
and any sound below can get a file of its own by adding `<name>.wav` (for example `correct.wav`); nothing else needs to change. When you replace one of the
four placeholder files, delete its line in `config/lamma.php` (`placeholders`) so `lamma:doctor` stops reporting it.

| Name | Plays when | Where | Source | Length |
| --- | --- | --- | --- | --- |
| `tap` | each answer arrives (pitch rises a little with each); toggles | host question; any toggle | synth | 0.1 s |
| `lock-in` | the player taps an answer | phone question | synth | 0.25 s |
| `join` | a new player appears in the lobby | host lobby | synth | 0.2 s |
| `ready-on` | a player taps Ready; the last player is ready ("Start game" unlocks, once) | phone lobby; host lobby | synth | 0.3 s |
| `ready-off` | a player taps Ready again | phone lobby | synth | 0.3 s |
| `countdown-tick` | each of 3, 2, 1 | host and phones | synth | 0.15 s |
| `countdown-go` | "Go!" | host and phones | synth | 0.7 s |
| `question-start` | a question opens | host | file `question-start.wav` (0.4 s), else synth (whoosh and chime) | 0.4-0.8 s |
| `timer-tick` | each of the last 5 seconds of a question | host and phones | file `tick.wav` (0.07 s), else synth | 0.1 s |
| `time-up` | the question timer reaches 0 (gentle low buzz) | host and phones | synth | 0.55 s |
| `reveal` | the answer is shown | host | file `reveal.wav` (0.6 s), else synth (swoosh) | 0.5-0.6 s |
| `score-count` | very short ticks while a "+100" counts up, rising in pitch (one number per screen) | host scoreboard top scorer; phone chip | synth | 0.05 s each |
| `rank-up` | a scoreboard row climbed this round (once) | host reveal | synth | 0.3 s |
| `correct` | the player was right (two rising notes) | phone reveal | synth | 0.6 s |
| `wrong` | the player was wrong or ran out of time (soft descending thud) | phone reveal | synth | 0.35 s |
| `streak` | 3 or more right in a row, after `correct` | phone reveal | synth | 0.45 s |
| `podium-bar` | each podium bar lands, 3rd then 2nd then 1st (lower for the higher step) | host results | synth | 0.3 s |
| `win` | the winner's name appears (host); first place (phone) | host results; phone results | file `podium.wav` (1.25 s), else synth | 1.1-1.25 s |

The two file names that differ from the sound names are `tick.wav` (sound `timer-tick`) and `podium.wav` (sound `win`); they keep those names.

Vote mode (Phase 11) adds no sound name: `question-start` when the vote opens (host), `tap` on the host for each vote (rising like the answer tick) and for each
hop of a tie's shuffle, `lock-in` and the `tap` buzz when a phone votes, `reveal` when the result lands (host and phone), and the vote's timer ticks like a
question's (`timer-tick`, `time-up`).

## Animations

Everything uses `transform`, `opacity` (and, for the drawn check mark, a stroke offset): no layout work. Everything is behind `motion-safe:`; under
`prefers-reduced-motion: reduce` movement becomes a plain fade, nothing pulses or shakes, and there is no confetti. (`AccessibilityTest` fails on any
animation class without `motion-safe:`.)

| Animation | Trigger | Duration |
| --- | --- | --- |
| `spring-in` (scale .6 to 1 with overshoot) | a new player row (host and phone lobby); the phone's lobby avatar | 450 ms |
| `tile-bounce` | the room-code tiles when the host lobby opens, one after the other | 700 ms each, 90 ms apart |
| wobble (Web Animations) | "Start game" once everyone is ready | 600 ms |
| countdown tile (Web Animations) | each number of 3 · 2 · 1 pops in and eases out; "Go!" pops in | 1000 ms per number |
| `fade-up` | the question text, the headlines | 250 ms |
| `pop-in` | host answer tiles (staggered 60 ms) | 250 ms |
| `chip-pop` | the check badge on an answered avatar; the phone's "+100" and streak chips | 350 ms |
| `ring-shake` | the host timer ring, in the last 3 seconds | 500 ms, repeating |
| `tick` | the timer number, in the last 5 seconds | 1 s, repeating |
| `bar-pulse` | the phone timer bar, under 5 seconds | 1 s, repeating |
| `reveal` + `ring-pulse` + confetti burst | the correct host tile (pops 1.04 and back; a ring grows out of it and fades; 18 pieces) | 300 ms; 800 ms |
| `flip` | scoreboard rows slide to their new place | 400 ms |
| `arrow-bob` | the small up arrow on a row that climbed | 700 ms, twice |
| `bar-grow` | podium bars, 3rd then 2nd then 1st | 600 ms each, 150 ms apart |
| `drop-in` | the crown | 300 ms, after the bars |
| `name-in` + confetti rain | the winner's headline scales in at 2.8 s; confetti rains for 6 s (at most 120 pieces, paused when the tab is hidden) | 600 ms; 6 s |
| `slide-in` | the phone's answer tiles (staggered 60 ms) and leaderboard rows, from the side where reading starts | 300 ms |
| `settle` | the tile the player picked grows and tilts into place | 450 ms |
| squish (Web Animations) + teal burst | the phone's Ready button turning on (16 pieces) | 300 ms |
| `wipe-in` | the teal "correct" screen wipes up from the bottom | 380 ms |
| `draw` | the check mark draws itself (after 250 ms) | 450 ms |
| `chip-pop` | the "+100" chip, with a burst of 22 confetti pieces | 350 ms |
| `shake` | the cross (or clock) circle on a wrong answer or time's up, once | 500 ms |
| `tile-drop` | the phone's rank tile drops in and lands tilted | 650 ms |
| `own-row` | the player's own leaderboard row slides in, then swells once | 1300 ms |
| `dots` | the waiting dots | 1.2 s, repeating |
| `pop-in` + `chip-pop` (Vote mode) | the three vote tiles pop in (staggered 60 ms); a tile's vote count pops each time it changes | 250 ms; 350 ms |
| hop (Vote mode, `lammaVoteLanding`) | a tie or no votes: the highlight hops across the tied tiles (scale 1.1), then lands on the winner with a pop (Web Animations) and an 18-piece burst; skipped under reduced motion | 150 ms a hop, about 1.2 s |

Confetti (`lammaConfetti.burst(origin, count)` and `lammaConfetti.rain(ms)`): one fixed canvas, `pointer-events: none`, `aria-hidden`, created when needed and
removed after the last piece lands; the four answer shapes in the brand colours (read from the CSS tokens); origins and drift are mirrored in right-to-left
pages.

## Checking it

`tests/Browser/MotionTest.php` runs the screens in a real browser at 390x844, 1280x720 and 1920x1080 (no JavaScript errors, no console output, no
accessibility problems, no scrolling on the host), once with reduced motion (no running transform animation, no confetti canvas), and plays a whole game
with the sound store recording what plays. `docs/manual-test-plan.md` has the "Sound and motion" section for real phones (sound unlock, haptics, mute
persistence, the iOS silent switch, the reduced-motion setting): the things that need ears and a real device.
