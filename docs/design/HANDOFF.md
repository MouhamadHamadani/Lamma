# Lamma · لمّة — Design Handoff

The UI spec for the Lamma quiz game, which runs on Laravel with Livewire 3, Alpine and Tailwind v4. Read it alongside the project Knowledge Base, which covers architecture, data model and events. This file only covers how things look and behave.

**What's in this folder**

| Path | What it is |
| --- | --- |
| `HANDOFF.md` | This spec |
| `lamma-theme.css` | Tailwind v4 `@theme` tokens + `sticker` / `wordmark` utilities. Import it into `resources/css/app.css` |
| `screens/*.html` | Static reference screens exported from the design canvas. Open them in a browser. They use hard-coded inline styles, so they're **reference only**; rebuild them with the tokens and Blade components below |
| `lamma-mark-rebuild.svg` | A rebuild of the logo mark. **Replace it with the official `lamma-icon.svg` / `lamma-logo-*.svg` from the brand kit** |

Each reference screen is fixed at its design size: 1440×900 for the host, 390×844 for phones, and full-length for the landing page. Build the real screens responsive (see Layout).

---

## 1. Design tokens

All values live in `lamma-theme.css`. Use the token names, not hex values.

| Token | Value | Use |
| --- | --- | --- |
| `cream` | #FFF7EC | Page background everywhere except the podium and the "correct" phone screen |
| `navy` | #1B1F4B | Text, all outlines, sticker shadows, answer D, dark panels |
| `coral` | #FF5A5F | Primary button fill, answer A, Arabic wordmark |
| `coral-700` | #C2343A | Coral **text** on light backgrounds (eyebrows, "Forgot password?", "Close room") |
| `sun` | #FFC23C | Answer C, timers, highlights, CTA on navy, rank #1 |
| `teal` | #2EC4B6 | Answer B, Ready and correct states |
| `ink-muted` / `ink-subtle` | #4B4F75 / #5B5F82 | Body copy / captions and hints |
| `line` / `line-strong` | #F0E3CF / #C9BBA3 | Borders and dividers / dashed placeholders and disabled |
| `tint-*` | teal, coral, sun, navy tints | Soft backgrounds for category tiles, icon wells, avatars, selected states |

**Contrast rules (must keep):**
- Text on coral, sun and teal is always **navy**, never white.
- Answer D is navy with **cream** text.
- Coral text on cream must use `coral-700`.

**Type**

| Role | Font | Size / weight |
| --- | --- | --- |
| Host question | display | 60px / 800 (reveal: 46px), with the AR line at 38px / 700 in `ink-muted` |
| Host answer text | display | 36px / 700 (AR 28px) |
| Page title | display | 44–48px / 800 |
| Section title | display | 28–34px / 800 |
| Phone title | display | 34–40px / 800 |
| Phone question | display | 25px / 700 (AR 27px) |
| Phone answer | display | 22px / 700 (AR 24px) |
| Body | sans | 16–18px / 400–500, line-height 1.6 |
| Label | sans | 15–16px / 600–700 |
| Eyebrow | sans | 14–15px / 700, uppercase, 1.5px tracking. **EN only**: in Arabic, no tracking and no uppercase |

Numbers (scores, timers, room codes) use the display font at 800. Use Western digits in both languages, as the Knowledge Base says.

---

## 2. Signature style: the "sticker"

The brand look is a thick navy outline (3px) with a hard offset shadow and no blur: `sticker-sm` 3px, `sticker` 4px, `sticker-lg` 6px.

- **Use it on:** primary buttons, answer tiles, room-code letters, the selected language option, the selected category, the "Your game" summary card, the big "I'm ready!" button and the avatar in the phone lobby.
- **On navy surfaces:** the shadow colour is `navy-900`. The one exception is the "Host a game" / sun CTA on the landing page's navy band, which gets a **coral** shadow.
- **Press state:** `sticker-press` moves the element 2px right and down and shrinks the shadow to 1px, over 120ms.
- **Don't use it on:** plain cards, inputs or chips. Those use a 2px `line` border, no shadow.
- **Playful tilt:** some sticker elements are rotated −4° to +4° (room-code letters, the "+N" chip, the locked answer card, the rank tile). Keep the tilt. It's static, so it doesn't need to change under reduced motion.

---

## 3. Blade components to build (`resources/views/components/lamma/`)

| Component | Props | Notes |
| --- | --- | --- |
| `<x-lamma.logo>` | `size` (sm 34 / md 48 / lg 64), `locale`, `onDark` | Mark + wordmark. EN: "Lamma" in cream fill with navy stroke. AR: «لمّة» in coral fill. On navy use a plain cream wordmark with no stroke. Use the **official SVGs** |
| `<x-lamma.button>` | `variant`: primary (coral sticker) · dark (navy fill, cream text, coral 3px shadow) · outline (3px navy or cream border) · ghost (text link); `size`: md 56 / lg 60–64; `icon`; `href` or `type` | Minimum height 44px. Disabled look: `cream` fill, 3px **dashed** `line-strong` border, `ink-subtle` text, lock icon |
| `<x-lamma.answer>` | `index` 0–3, `text`, `textAlt` (other language), `size` host / phone, `state` default · selected · locked · correct · faded | A = coral + triangle, B = teal + circle, C = sun + square, D = navy + diamond (cream). **The shape is required**, so colour-blind players can tell answers apart. Host tile is 150px tall (124px on reveal) with radius 22. Phone tile is 88px tall with radius 20 and a 44px shape well at 35% white |
| `<x-lamma.timer-ring>` | `endsAt` | Host only. 150px ring: `line` track, `sun` arc of 12px, number at 56px/800 with a "seconds" caption. Alpine counts down from `ends_at` |
| `<x-lamma.timer-bar>` | `endsAt` | Phone only. 14px bar with a 2px navy border, white track, `sun` fill, and the seconds at 22px/800 |
| `<x-lamma.room-code>` | `code`, `size` hero / chip | Hero: 120×144 tiles, 92px letters, `sticker-lg`, colours cycle coral → teal → sun → white, alternating tilt. Chip: pill with 18px/800 letters and 3px tracking |
| `<x-lamma.avatar>` | `name`, `color`, `size` 36–120 | First letter of the name, 3px navy ring. Colour comes from the player index: tint-coral, teal, sun, … |
| `<x-lamma.player-row>` | `player`, `showLanguage`, `showReady` | 76px row on the host, 56px on phones. Language badge (EN / ع) and a status pill |
| `<x-lamma.status-pill>` | `ready` | Ready: `tint-teal` fill, 2px teal border, check icon. Not ready: white fill, 2px **dashed** `line-strong` border, `ink-subtle` text |
| `<x-lamma.segmented>` | `options`, `wire:model` | `cream` container with a 2px `line` border and radius 16. Selected option: navy fill, cream text, weight 700. Buttons are 48px tall with `aria-pressed` |
| `<x-lamma.category-toggle>` | `category`, `selected` | 72px tall. Off: white fill, 2px `line` border, empty ring. On: tint fill, 3px navy border, `sticker-sm`, navy check badge |
| `<x-lamma.chip>` | `tone` | 36–40px pill |
| `<x-lamma.confetti>` | — | Decorative shapes (triangle, circle, square, diamond) in brand colours, `aria-hidden`. Mirror their positions in RTL |

Icons are 24px line icons with a 1.8 stroke and round caps (the reference screens have them inline). Heroicons Outline or Lucide are close matches. Don't use emoji.

---

## 4. Screens

Routes and Livewire components follow the Knowledge Base. Host components are designed at 1440×900; player components at 390×844.

### Public
| Reference | Route | Component | Notes |
| --- | --- | --- | --- |
| `landing-desktop`, `landing-phone-en`, `landing-phone-ar` | `/` | Blade page | The hero has a "Got a room code?" join box (posts to `/join?code=`) and a **Host a game** button (→ `/rooms/create`, or login if the user is a guest). Categories are examples; load the real ones from `categories` |
| `host-1-login` | `/login`, `/register` | Breeze / Fortify views, restyled | Split layout: 600px navy brand panel on the left, form on the right. The Log in / Sign up segmented control swaps between the two forms. "Joining a friend's game?" → `/join` |

### Host (laptop or TV)
| Reference | Route | Component | Room status |
| --- | --- | --- | --- |
| `host-2-create-room` | `/rooms/create` | `Host\CreateRoom` | — |
| `host-3-lobby` | `/host/{code}` | `Host\HostLobby` | lobby |
| `host-4-question` | `/host/{code}` | `Host\HostGame` (state `question`) | playing |
| `host-5-reveal` | `/host/{code}` | `Host\HostGame` (state `reveal`) | playing |
| `host-6-podium` | `/host/{code}` | `Host\HostResults` | finished |

- **Create room**
  - Categories are multi-select, and at least one is required: show an inline error under the grid and disable "Create room" until one is picked.
  - Defaults: 10 questions, 15 s, Mixed difficulty, Both languages, double points on the last question.
  - The summary card updates live.
- **Lobby**
  - The player list updates live from the presence channel. New rows fade and slide in over 200ms.
  - The empty state is one dashed "Waiting for more players…" row, which always stays at the end of the list.
  - "Start game" stays disabled until ≥1 player and all are ready. The helper text reads "Unlocks when everyone taps Ready."
  - `[YOUR DOMAIN]/join` and the QR code are placeholders: generate the QR with `simplesoftwareio/simple-qrcode` pointing to `/join?code=K7MP`.
- **Question**
  - Shows question 3 of 10 as a progress bar: done = navy dots, current = a wide coral pill, upcoming = `line`.
  - The category chip uses the category's tint.
  - When the room language is Both, EN is the main line and AR the secondary line, and each tile shows EN on the start side and AR on the end side. With EN or AR only, show one language and drop the secondary line and `textAlt`.
  - The "2 of 3 answered" avatars get a teal check badge as each `PlayerAnswered` arrives; players who haven't answered sit at 40% opacity.
- **Reveal**
  - Wrong tiles fade to 35%. The correct tile keeps full colour, gets `sticker-lg`, and shows a white "Correct" badge pinned to its top edge.
  - Mini avatars on each tile show who picked it.
  - Right side: the navy Scoreboard panel. Rows show rank, avatar, name, total and the points gained; the leader's row is lighter (`navy-600`) with a sun rank number.
  - "Next question in 5 s" is a sun progress bar with a **Next question** button (host override).
- **Podium**
  - Full navy background with confetti. On the left: "Ali wins!" (92px) with the Arabic line in coral, **Play again** (primary, same players) and **New game** (outline cream).
  - On the right: the podium in order 2 · 1 · 3, bar heights 190 / 270 / 140, colours teal / sun / coral, and a crown above #1.

### Player (phone)
| Reference | Route | Component | State |
| --- | --- | --- | --- |
| `player-1-join` | `/join` | `Player\JoinRoom` | — |
| `player-2-lobby` | `/play/{code}` | `Player\PlayerLobby` | lobby |
| `player-3-question`, `-ar` | `/play/{code}` | `Player\PlayerGame` | question |
| `player-4-answered` | `/play/{code}` | `Player\PlayerGame` | answered |
| `player-5-correct` | `/play/{code}` | `Player\PlayerGame` | reveal (correct) |
| `player-6-results` | `/play/{code}` | `Player\PlayerResults` | finished |

- **Join**
  - The room code is prefilled from `?code=`. It's uppercased and always `dir="ltr"`, with a max of 6 characters and no look-alike characters (0/O, 1/I).
  - The language choice defaults to the browser language.
  - Errors appear inline under the field in `coral-700` with an icon: "We couldn't find that room", "This game has already started", "That nickname is taken in this room".
- **Lobby**
  - The big **I'm ready!** toggle is teal with a check when on. When off, it's a coral primary button labelled "I'm ready".
  - The list shows "2 of 3 ready", and the current player is marked "(you)".
- **Question**
  - The top row holds the question number and the player's score chip.
  - Tapping an answer locks it immediately and moves to the Answered state. There's no confirmation step, which keeps it fast.
  - Buttons have a 72px minimum tap target (they're 88px in the design).
- **Answered**
  - The chosen tile appears large and tilted, labelled "Answer locked in".
  - Three dots pulse: 1.2s loop, staggered 150ms.
  - Shows "2 of 3 answered", and the timer keeps running.
- **Reveal – correct**
  - Full teal background, a cream check circle, **Correct!** and a sun "+N" chip, with "100 + 65 speed" under it (section 11).
  - Shows the player's rank and total, plus a card with the correct answer.
- **Reveal – wrong (not drawn; build it like this)**
  - Keep the **cream** background, not red.
  - Use a `tint-coral` circle with a navy ✕ (3px sticker) and the heading "Not quite!"; in Arabic, «ليس هذه المرة!».
  - Show a `line` chip reading "+0", then the same rank line and correct-answer card as the correct screen.
- **Reveal – no answer**: same as wrong, with the heading "Time's up!" / «انتهى الوقت!».
- **Results**
  - A big tilted rank tile shows "2nd"; use sun for 1st, teal for 2nd and coral for 3rd and below.
  - Below it: the score line and the leaderboard, with the player's own row given a 3px navy border.
  - Then "Stay here…" helper text and a **Leave room** outline button.

---

## 5. Layout and responsiveness

- **Host screens** are designed at 1440×900 and must work from 1280×720 up to 1920×1080 and TVs.
  - Use a `max-w-[1600px]` centred container.
  - Scale type with `clamp()`. For example, question text is `clamp(40px, 4.2vw, 72px)`, answer text `clamp(24px, 2.5vw, 40px)` and room-code letters `clamp(64px, 6.5vw, 120px)`.
  - Answers stay in a 2×2 grid. Nothing on the host scrolls during a game.
- **Phone screens** are designed at 390×844 and should work from 360 to 430 wide.
  - Use `min-h-dvh` flex columns with the primary action pinned to the bottom (safe-area padding).
  - Answers stack in one column.
  - On heights under 700px, shrink the answer tiles to 72px.
- **Landing**
  - At ≥1024px it has 120px side gutters. Below 1024px, switch to the phone layout: single column, 20px gutters.
  - Tablet (768–1023): use a 2-column category grid and stack the hero illustration under the text.

## 6. Arabic and RTL

- Set `lang` and `dir` on `<html>` from the player's locale (host: from the room setting; when it's Both, use `dir="ltr"` and mark the Arabic lines with `lang="ar" dir="rtl"`).
- Use logical utilities only (`ms-`, `me-`, `ps-`, `pe-`, `start-`, `end-`, `text-start`), so layouts mirror automatically.
- Mirror confetti positions and tilts in RTL.
- These always stay `dir="ltr"`: room codes, nickname inputs, scores and timers.
- Arabic text never gets letter-spacing or uppercase (enforced in `lamma-theme.css`), and uses sizes about 2px larger than English at the same level.
- The language toggle shows the **other** language: an EN page shows «عربي», and an AR page shows "EN".

## 7. Motion

| Element | Trigger | Animation | Duration / easing |
| --- | --- | --- | --- |
| Sticker buttons and tiles | press | translate 2px, shadow 4→1px | 120ms ease-out |
| Question and answers (host) | `QuestionStarted` | Question fades up 12px, then tiles pop in (scale .9→1) staggered 60ms | 250ms ease-out |
| Timer ring / bar | tick | Linear drain synced to `ends_at`. Under 5 s, the ring turns coral and the number pulses once per second | linear |
| Reveal | `QuestionRevealed` | Wrong tiles fade to 35%, the correct tile pops to 1.04 then back to 1, the badge drops in | 300ms, spring-ish `cubic-bezier(.34,1.56,.64,1)` |
| Scoreboard rows | `ScoreboardUpdated` | Rows re-order with FLIP, the "+N" counts up | 400ms ease-in-out |
| "+N" chip (phone) | reveal | Scale 0→1 with a little overshoot | 350ms spring |
| Podium bars | `GameFinished` | Bars grow from 0 in order 3 → 2 → 1, then the crown drops | 600ms each, 150ms stagger |
| Waiting dots | answered | Opacity pulse | 1.2s loop |

Respect `prefers-reduced-motion`: replace movement with simple fades and don't pulse.

## 8. Accessibility

- Use real `<button>`, `<a>`, `<input>` and `<label>` elements (the reference screens already do). Toggles use `aria-pressed`, and the disabled Start button uses `disabled` with its reason shown as text.
- Answer buttons get an accessible name like "Answer B: Mars". The shape SVGs are `aria-hidden`.
- Announce these in an `aria-live="polite"` region:
  - "Question 3 of 10"
  - "Answer locked in"
  - "Correct, plus 165 points" or "Not quite, the answer was Mars"
  - "Double points" after the question and the answer on the last question, when it counts double
  - Lobby joins and leaves on the host
- Announce the timer only at 10 s and 5 s, not every second.
- Focus order follows reading order. Show a visible focus ring as a 3px `sun` outline with a 2px offset (`focus-visible` only).
- Every text colour combination above passes WCAG AA. Don't put white text on coral, sun or teal.

## 9. Decisions baked into these designs (confirm or change)

- **Accounts:** the host has an account; players join as guests with a nickname, and login is optional.
- **Timer:** 10, 15, 20 or 30 s per question, with 15 s the default (Phase 12).
- **Scoring:** 100 per correct answer plus a speed bonus of 0-100, and the last question counts double (Phase 12, section 11).
- **Answers on phones:** full text on phones (with colour and shape).
- **Host plays too:** the big screen never plays; the host can join from their own phone like anyone (Phase 12).
- **Difficulty:** Easy / Medium / Hard / Mixed.
- **Not designed yet:**
  - Admin panel: keep the Filament defaults and set Filament's primary colour to coral and its font to IBM Plex Sans Arabic.
  - Reconnecting state: a simple centred card saying "Reconnecting…" with pulsing dots.
  - Host screens in Arabic only: mirror the English ones.

## 10. Vote mode (Phase 11, built without design files)

The second game mode: the host picks the first category and a pool, and after every 3, 5 or 10 questions the phones vote on the category of the next ones.
There were no design files for it; the screens are built from the existing components and the closest reference screens, and keep the motion language of
section 7. Look at them in the gallery (`/_components`, "Answers · vote") and in a game.

| Screen | Built from | What it shows |
| --- | --- | --- |
| Create room, Vote | host-2-create-room | A **Classic / Vote** `<x-lamma.segmented>` beside the title (so a Classic setup is as tall as before). Vote replaces the category grid with **First category** (one `<x-lamma.subcategory-chip>` per top-level category, single choice), **Categories to vote on** (`<x-lamma.category-toggle>` tiles, top-level only; the first one is on and locked) and **Vote every 3 / 5 / 10** (segmented). A missing step takes the hint's place in coral; a category that cannot fill a batch shows its count on its tile and in an error under the grid. The summary card says *Vote · N categories*, the first category and *Every N questions · M votes*. On a short laptop screen (`laptop-short`) the first-category chips, their label and Vote every share one line, so nothing scrolls at 1280x720. |
| Host vote | host-4-question | The question title's place: **Choose the next category** (+ the Arabic line in Both), the timer ring (15 s; screen readers hear 5 s only). Three **upright answer tiles** (`<x-lamma.answer size="vote">`: colour + shape, name, Arabic name in Both, a third of the screen tall, three across) with a **count chip** on the top corner that pops when it changes. Footer: the players' avatars with a check once they voted, "N of M voted", **+10 s** (once per vote, section 11) and **Skip vote**. The progress dots get a small upright marker after each batch (coral while that vote is on). |
| Host result (3 s) | host-5-reveal | The winner keeps the correct-answer treatment (sticker-lg shadow, the ring, a drop-in badge that says **Winner**) and is enlarged to 105%; the others fade to 35%; mini avatars on each tile show who voted for it (a crowd becomes "+N"). Title: *The next category is*, or **Tie, picking randomly** / **Nobody voted, picking randomly** (+ Arabic). For a tie or no votes the highlight **hops** across the tied tiles (150 ms a hop, a soft tap each, about 1.2 s) before it lands on the winner with the reveal sound, a pop and a confetti burst. Under reduced motion it lands at once, without the hop. |
| Phone vote | player-3-question | The timer bar, a card with **Choose the next category**, three stacked phone answer tiles in the player's own language. One tap locks the vote (the lock-in sound and the tap buzz), no confirm step. |
| Phone voted | player-4-answered | *Vote locked in*, the chosen tile big and tilted (`state="locked"`), the pulsing dots, "Waiting for the others…", "N of M voted". Nothing about anyone else's vote. |
| Phone result | player-5 | The winner as the big tilted tile, the tie / no-votes line, **Your pick won!** (chip-pop) when it was this player's, or a sun chip **You didn't vote**. No score, no rank: the next question follows. |

Motion: the tiles pop in staggered like answers (`pop-in`, 60 ms), the count chip `chip-pop`s on each change, the phone tiles `slide-in`, the locked tile `settle`s;
every one of them is behind `motion-safe:` with a fade under reduced motion, and the hop is skipped. Sounds: the question-start chime when the vote opens, the reveal
swoosh for the result, the soft answer tick on the host for each vote (no new sound names). Each cue is a `$store.sound.once` so a refresh does not replay it, and the
timers and the result's hop are `wire:ignore`.

## 11. Speed bonus, the double final question, and the host's phone (Phase 12, built without design files)

Tension without new screens: everything below sits on an existing screen. Look at the chip in the gallery (`/_components`, next to the streak chip).

| Where | What | Notes |
| --- | --- | --- |
| Phone reveal, correct | The real **+N** in the sun chip, and under it a small line **100 + 65 speed** (15px, bold, navy on teal). The numbers are isolated, so Arabic reads «100 + 65 للسرعة» from the right. | Wrong and time's up are unchanged: "+0", no line. An answer from before the speed bonus has no line. |
| `<x-lamma.double-chip>` | The **x2** sticker: coral, navy text, 3px navy outline, `sticker-sm`, tilted -3°, `chip-pop` with a fade under reduced motion. `size` md (phone) / lg (host). The text is the same in every language, so Both needs no second line; a screen reader hears "Double points". | Only when the question counts double: the game's last question, with the setting on. |
| Host question | The chip on the top corner of the timer ring. | It is outside the layout, so the last question fits exactly as the others do. |
| Host reveal | The chip after "N of M got it right". | |
| Phone question | The chip at the end of the category row in the question card. | |
| Phone reveal, correct | Under the speed line: the chip + **final question**. | Wrong and time's up: nothing (+0 is +0). |
| Create room | **Double points on the last question** On / Off, the last of the six settings (now three rows of two). On a short laptop screen the label sits beside its switch in 14px, so the row does not grow. The summary card has a **Last question** row: *Double points* / *Normal points*. | The settings' controls line up on the bottom edge of their row. In a Vote setup at `laptop-short` the summary rows are a little tighter, to make room for it. |
| Create room, Vote | **Pick 4 or more so every vote has a real choice.** in the hint's place beside "Categories to vote on", when the pool has exactly 3. Muted, not coral: it is a hint, not a rule. | |
| Host vote | **+10 s** beside **Skip vote**: a pill with a 2px `line` border, the display font, 44px tall, a soft tap. Gone once pressed. Both timers jump to the new deadline and the ring draws against the longer length. | Screen readers hear "Vote extended" on the host and on a phone that has not voted. |
| Host lobby | **Want to play too? Join from your phone with this code.** under "Each player picks Arabic or English when they join.", with the phone icon. | |

## 12. Question types: true/false, pictures, closest number, the fun fact (Phase 13, built without design files)

Three kinds of question and two extras, all on the existing screens and components, in the motion language of section 7. A **picture question** is not a kind of its own: it is a
multiple-choice or true/false question (or a number question) with a picture. Look at everything in the gallery (`/_components`, "Question types" and "Closest number").

| Where | What | Notes |
| --- | --- | --- |
| Host question, true/false | An eyebrow **True or false?** (coral-700, + the Arabic line in Both) above the title, and **two big tiles** (`<x-lamma.answer :tall>`): A coral triangle **True** / «صحيح», B teal circle **False** / «خطأ», each about 26 dvh tall (130-260 px) with the words at 36-56 px. | True is always A and False always B, on every screen: the order is fixed when the question is drawn. The shape is still required. |
| Host reveal, true/false | The right tile pops with the Correct badge, the other fades to 35%. Who picked what stands in the tile's **bottom corner** (up to 4 avatars and "+N"), under the words, where there is room. | A tall tile has more room than a normal one, so the avatars move out of the line of text. |
| Host, a picture | The picture sits **beside the title, above the options**: a navy-outlined 4:3 frame (`<x-lamma.question-image>`), 23 dvh high (96-230 px), `object-contain`. The title and its Arabic line drop one size, and the tiles become **compact** (`<x-lamma.answer :compact>`, 11.5 dvh) with the smaller answer type, so nothing scrolls at 1280x720. On the reveal the picture shrinks to 14 dvh and the tiles to 10 dvh. | The alt text is the same for every question, **"Picture for this question"** (translated): it never describes the picture, so it can never give the answer away. A picture that fails to load takes its frame with it. |
| Host question, closest number | No tiles. A dashed panel: a sun disc with a target icon, **Type your guess on your phone** (+Arabic in Both), "The closest guess wins a bonus.", the players' avatars (56 px, 40% until they guess, a teal check after) and **N of M guessed**. The footer keeps only Skip timer. | Nobody's number is ever on the big screen before the reveal. |
| Host reveal, closest number | **The number line** (`<x-lamma.number-line>`): the guesses as avatars standing on a navy line, a pin whose neighbour is close one step higher, the closest with a sun ring, and the right number under the line in a teal chip with a check. Below it **the ranked guesses** (`<x-lamma.guess-list>`): place, avatar, name, a sun **Closest** badge (a tie shares it), the guess, **Off by N** or **Exact**, and **+points**. Then the fun fact, and **N of M were close enough**. | The list shows 3 rows (2 when there is a fun fact) and "and N more": the scoreboard beside it lists everyone, and the screen never scrolls. The title is one size smaller here too. |
| Phone question, true/false | A small coral **True or false?** line in the question card, and **two tall tiles** (128 px, 96 px on a short screen): shape well, then True / False in the player's language. | The tap targets are bigger than the 72 px minimum. |
| Phone question, a picture | The picture sits in the question card under the title, full width, 19 dvh high (88-200 px), 14 dvh (72-120 px) on a screen under 700 px tall. | **The picture shrinks, never the tiles:** they keep 88 px (72 px on a short screen). A 640 px-tall phone scrolls a little. |
| Phone question, closest number | A **Your guess** label, one big left-to-right field (80 px, 40 px digits, a decimal keypad: `inputmode="decimal"`, `dir="ltr"`) and one coral **Lock in my guess** button (72 px) that stays dashed and locked until there is a digit. One go, like a tap. A typed non-number gets a coral message under the box and locks nothing. | The box is `wire:ignore`: a refresh never wipes a half-typed number. Arabic-Indic digits and the Arabic decimal and thousands separators are read by the server and shown back in Western digits. iOS's decimal keypad has no minus sign, so a negative answer cannot be typed there: write such questions another way. |
| Phone answered, closest number | **Guess locked in**, then a tilted sun sticker with a target icon, "Your guess" and the number at 44 px, the pulsing dots, **N of M guessed**. | Built like the locked answer tile. |
| Phone reveal, closest number | Teal for a guess that scored, cream otherwise. Headings: **Spot on!** (the exact number), **Close!** (inside the tolerance), **Not quite!**, **Time's up!**. The sun chip has the real +N; under it **100 + 50 closest** when the guess was the closest, and the x2 line on the last question (there is no speed line). Then a card with **Correct answer** and **Your guess**. | No speed bonus on a number: thinking takes time. The +50 for the closest guess (a tie shares it) is decided at the reveal; the last question doubles all of it. |
| The fun fact (`<x-lamma.fun-fact>`) | A sun disc with a bulb and one or two short lines **under the correct answer**, never before it. Host: up to 2 lines in the main language and, in Both, the Arabic line under it, 15-21 px, no caption (screen readers hear "Fun fact"). Phone: a white card with a visible **Fun fact** caption and up to 3 lines. | The 5-second reveal and the host's Next question work as ever. Optional: a question without one shows nothing, and a fact written in one language only shows in that language. It is also read out with the answer. |

Motion: the guess pins and the answer marker drop in one after another (90 ms apart, `drop-in`), the ranked rows slide in (`row-in`, 60 ms apart), the locked number card `settle`s like a locked tile; each is behind `motion-safe:` with a plain fade under reduced motion. Sounds: nothing new. The number box's button plays the lock-in sound and the tap buzz like a tile, the host's answered tick plays for every guess, the reveal uses the usual reveal sound; each cue is a `$store.sound` call that a Livewire refresh cannot repeat.

Accessibility: the live regions say **True or false?** and **Type your guess below.** with the question, **Guess locked in**, and on the reveal the answer ("Correct, plus 150 points. The answer was 1969"), the fun fact, and "Double points" as before. The number field has a visible label, the refusal is `role="alert"`, the number line has a screen-reader list of the guesses. The new texts are in both lang files; numbers sit in `<bdi dir="ltr">` and are Western digits in Arabic too.

## 13. The demo, the landing page, the share card, nicknames (Phase 14, built without design files)

A way to feel the game without an account, a picture to post when it ends, and a few fixes. Everything is built from the existing components and keeps the motion language of
section 7. Look at the new component in the gallery (`/_components`, "Share result") and at the screens in a game.

| Where | What | Notes |
| --- | --- | --- |
| `/demo` | A two-column page like the landing page's "How it works": the eyebrow **Try a demo**, the headline **Feel the game in two minutes.**, one line (**No account needed. Open this on a laptop or TV, then scan the code with your phone.**), the big **Start a demo game** button (a real form post) and three numbered cards (start on the big screen, scan the code, play 4 questions against two bots). | Under 768 px a sun-tinted note says **You're on a small screen. The demo works best on a laptop or TV...**, and the button still works. A refused start (5 an hour from one address, or too few questions) shows a coral error under the button. The analytics notice shows here, like on the landing page. |
| Host lobby, a demo | A teal **Demo** chip beside **Lobby**. **Sara** (EN) and **Karim** (ع) are ordinary player rows, Ready, with a small outlined **Bot** tag after the name and no remove button. The hint under the room code reads **Scan the code with your phone to play. The two bots are ready and waiting.** Start stays locked until a real player has joined and tapped Ready (**Unlocks when you have joined from your phone and tapped Ready.**). | After 90 seconds with nobody real, the dashed "Waiting for more players…" row turns into a sun-tinted **Waiting for you? Scan the code** (a status role, no animation). The bots stay ready. |
| Phone lobby and results, a demo | The same **Bot** tag on Sara and Karim in the player list and the leaderboard. The results screen has no **Save your score** card: a white card says **Liked it?** with **Host your own game** (coral, to sign up) and **Join a friend's game** (outline, to the join page). | A demo is not a saved game. |
| Host podium, a demo | Where **Play again** and **New game** stand: **Host your own game** (to sign up) and **Join a friend's game** (to the join page). The line under the headline reads **That was the demo. Ready for a game with your own friends?** | A demo cannot be played again. |
| Landing, the buttons | **Host a game** (coral) with **Try a demo** (navy outline) beside it, side by side from 640 px up, stacked on a phone. Under them a small line, **Hosting and playing are free for now.** (also under the Host button of the navy band at the end), controlled by `LAMMA_LANDING_FREE_LINE`. Then **See how it works**. | **Host a game** now goes to room creation for everyone: a guest is sent to log in (or sign up) by the page and comes back. |
| Landing, "See how it works" | With `public/media/how-it-works.mp4` in place it opens the clip in a dialog (title **How it works**, the video with its controls, a **Close** button); without the file it scrolls to the three steps as before. | An optional poster (`how-it-works.jpg`, `.png` or `.webp`) and optional captions per language (`how-it-works.en.vtt`, `how-it-works.ar.vtt`; the page language is the default track). The video is `preload="none"` and pauses when the dialog closes. |
| Landing, the mockup | The two phones now show **name**, a small **EN** / **ع** pill and the timer as three separate things, and each phone and card is tagged `lang="en"` or `lang="ar"`: the English phone and card on the Arabic page are English on purpose (the point is Arabic and English in one room). | One labelled navigation in the header (**Main menu**) and one in the footer (**Footer menu**); the phone menu exists only while it is open. |
| Nickname refused | **Please choose another nickname.** in the same coral inline style under the nickname field as **That nickname is taken in this room**, on the join page and in the lobby's name box. It never says which word. | A name that the list refuses today (the list grew after the player joined) is shown as **Player** («لاعب») on every screen. |
| `<x-lamma.share-button>` | An outline button with the **share** icon and **Share result** (outline cream on the navy podium, outline navy on a phone). One tap draws the card and opens the share sheet, or downloads a PNG. Below it a small status line: **Result shared** / **Result saved to your device** / **We couldn't make the picture. Try again.** | The status fades in (`fade-in`) behind `motion-safe:` and is plain text under reduced motion. Closing the share sheet says nothing. |
| The card (1080x1350 PNG) | Navy, with the four brand shapes scattered (mirrored in Arabic), the stacked logo, **Game over · N questions** in sun, the headline in cream (**Sara wins!**, a tie, two winners, or **No winner this time**), then up to three rows: a rank disc (sun, teal, coral), the name, the score in sun, and on a phone a cream band **Your place: 2nd** under them, then **The quiz night for everyone** and the site address in sun. | Drawn in the browser on a canvas (the browser shapes Arabic), in Baloo Bhaijaan 2, with the colours read from the CSS tokens. Right to left in Arabic (disc on the right, score on the left). A long name shrinks, then is cut with an ellipsis. No photo, no room code, no account. |
| Admin dashboard | A **Funnel · last 30 days** row of six figures under **Active rooms**: Rooms created, Games started (% of rooms), Games finished (% of started), Rematch rate, Average players, and **Demo games** (blue) with how many were played to the end and how many people signed up on the same browser. | Computed from the database, not from Google. Demo games and their bots are not in the totals. |

Motion: nothing new moves except the share status line's fade. Sounds: nothing new. The demo uses the real game's screens, sounds and timers.

Accessibility: the share button is a real `<button>` with `aria-busy` while it draws and a `role="status"` line; the demo page's small-screen note is plain text; the **Waiting for you?** row has `role="status"`; the **Bot** tag is text, not colour.

## 14. Follow-ups: "Next: double points", more time for a number, a longer reveal, the join link (Phase 14.1, built without design files)

Small changes on existing screens; look at the new component in the gallery (`/_components`, next to the x2 chip).

| Where | What | Notes |
| --- | --- | --- |
| `<x-lamma.double-next>` | A **sun-tinted pill** (`bg-tint-sun`, 3px navy outline, `sticker-sm`, `chip-pop` with a fade under reduced motion) holding the **x2 sticker** (the same coral sticker as `<x-lamma.double-chip>`, tilted -3°, 28 px on a phone / 36 px on the big screen) and **Next: double points** in the display font, 15 px (md, phone) or 20 px (lg, big screen). `lang` is the screen's language. | The sticker is `aria-hidden`; the words are what a screen reader meets, and the screen's live region also says it. |
| Host reveal | At the end of the **N of M got it right** row (`ms-auto`), only on the reveal of the **second-to-last** question. Arabic room: **التالي: نقاط مضاعفة**. In Both it is the English line only, like the other labels in that footer (Next question in, Scoreboard): the sticker needs no translation. | Measured at 1280x720 and 1920x1080 with eight players, a number question and a fun fact: nothing scrolls, the row does not wrap. |
| Phone reveal | Takes the place of **Next question coming up…**, after a right answer, a wrong one and "Time's up" alike. On a phone under 700 px tall the reveal is a little tighter so the extra height does not scroll it (gap 12 px, headline 44 px, the +N chip 56 px, cards `py-3`, 12 px under the content). | 360x640 with a number question, a fun fact and the closest-guess line fits without scrolling. |
| Live regions | The big screen's and the phone's announcer end with **Next: double points** on that reveal. | Same words as the pill. |
| When | The second-to-last question of the **game**, with *Double points on the last question* on and at least two questions; in Vote mode the second-to-last of the whole game (not of a batch); in a demo too. Never with the setting off, never in a one-question game. | `ScoringService::doublesNext`. |
| Timers | The ring (big screen) and the bar (phone) of a **closest-number question** run 5 s longer than the room's time per question (15 s becomes 20 s), because typing takes longer than a tap. They draw against the question's own length, so the ring starts full. | `lamma.number_question_extra_seconds`. |
| Host reveal bar | **Next question in N s** counts **8 s** after a number question or any question with a fun fact, **5 s** after a plain one, and the sun bar fills against that length. **Next question** still skips. | `lamma.reveal_seconds`, `lamma.reveal_seconds_long`. |
| Host lobby, a demo | The dashed **Waiting for you? Scan the code** row (after 90 s with nobody real) gains a second line: **Join from this laptop**, an underlined 15 px bold navy link (24 px tall at least) that opens the join page in a **new tab** of the same browser. The row is a sun-tinted status block as before. | Gone once somebody real has joined. The big screen stays the big screen (see the presence-channel rule in CLAUDE.md). |
| Admin dashboard | A fifth figure beside Categories, Questions, Users and Rooms: **Nicknames refused today**, with **N in the last 7 days** under it. | A count only: never the word. |

Motion and sound: the pill pops like the other chips (`chip-pop`), nothing else moves, no new sound.
