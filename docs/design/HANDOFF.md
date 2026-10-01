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
- **Playful tilt:** some sticker elements are rotated −4° to +4° (room-code letters, the "+100" chip, the locked answer card, the rank tile). Keep the tilt. It's static, so it doesn't need to change under reduced motion.

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
  - Defaults: 10 questions, 20 s, Mixed difficulty, Both languages.
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
  - Full teal background, a cream check circle, **Correct!** and a sun "+100" chip.
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
| Scoreboard rows | `ScoreboardUpdated` | Rows re-order with FLIP, the "+100" counts up | 400ms ease-in-out |
| "+100" chip (phone) | reveal | Scale 0→1 with a little overshoot | 350ms spring |
| Podium bars | `GameFinished` | Bars grow from 0 in order 3 → 2 → 1, then the crown drops | 600ms each, 150ms stagger |
| Waiting dots | answered | Opacity pulse | 1.2s loop |

Respect `prefers-reduced-motion`: replace movement with simple fades and don't pulse.

## 8. Accessibility

- Use real `<button>`, `<a>`, `<input>` and `<label>` elements (the reference screens already do). Toggles use `aria-pressed`, and the disabled Start button uses `disabled` with its reason shown as text.
- Answer buttons get an accessible name like "Answer B: Mars". The shape SVGs are `aria-hidden`.
- Announce these in an `aria-live="polite"` region:
  - "Question 3 of 10"
  - "Answer locked in"
  - "Correct, plus 100 points" or "Not quite, the answer was Mars"
  - Lobby joins and leaves on the host
- Announce the timer only at 10 s and 5 s, not every second.
- Focus order follows reading order. Show a visible focus ring as a 3px `sun` outline with a 2px offset (`focus-visible` only).
- Every text colour combination above passes WCAG AA. Don't put white text on coral, sun or teal.

## 9. Decisions baked into these designs (confirm or change)

- **Accounts:** the host has an account; players join as guests with a nickname, and login is optional.
- **Timer:** 10, 20 or 30 s per question, with 20 s the default.
- **Scoring:** a flat +100 per correct answer. The UI already has room to show a speed bonus in the "+N" chip.
- **Answers on phones:** full text on phones (with colour and shape).
- **Host plays too:** no, the host only runs the big screen.
- **Difficulty:** Easy / Medium / Hard / Mixed.
- **Not designed yet:**
  - Admin panel: keep the Filament defaults and set Filament's primary colour to coral and its font to IBM Plex Sans Arabic.
  - Reconnecting state: a simple centred card saying "Reconnecting…" with pulsing dots.
  - Host screens in Arabic only: mirror the English ones.
