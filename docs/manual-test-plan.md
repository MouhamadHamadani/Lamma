# Manual test plan: one laptop, three phones

A full run of Lamma by hand, start to finish. It takes about 45 minutes. Tick each box as you go and write down anything that
differs from "Expect". For how to start the servers and put phones on the laptop's network, see
[realtime-testing.md](realtime-testing.md); this file assumes that works.

## What you need

| | |
| --- | --- |
| **Laptop (host)** | a browser on `http://<laptop-ip>:8000` (not `localhost`: the join address and QR code are built from the address you open). Window 1280×720 or bigger. |
| **Phone A** | a **guest, in Arabic** |
| **Phone B** | a **guest, in English** |
| **Phone C** | a **logged-in player** (English), using the seeded account `test@example.com` / `password` |
| Host account | `english@example.com` / `password` (seeded, local only) |
| Admin | `/admin`, with `ADMIN_EMAIL` / `ADMIN_PASSWORD` from `.env` |

Running: `php artisan serve --host=0.0.0.0`, `php artisan reverb:start`, `php artisan queue:listen --tries=1 --timeout=0 --sleep=1`,
`php artisan schedule:work`, and built assets (`npm run build`). `composer dev` starts all of these except the LAN binding.
Use a **private window or another browser profile** for each phone you test on the laptop instead of a real phone; two phones in
one browser share a cookie jar and would be one guest.

Start clean: `php artisan tinker --execute='App\Models\Room::query()->get()->each->delete();'`

Pass criteria: no error pages, nothing that needs a reload to appear, nothing scrolls on the host screen, every string is in the
right language, scores add up.

---

## 1. Create the room (laptop)

- [ ] Log in as the host. **Host a game** opens *Set up your game*.
- [ ] Pick **at least two categories**, **5 questions**, **10 s**, **Mixed**, language **Both**.
  Expect: the summary card updates live; with no category picked, *Create room* is disabled with "Pick at least one category."
- [ ] **Create room.** Expect: the lobby with the room code (big tilted tiles), a QR code, "Go to <address>/join and enter this room code",
  the same line in Arabic under it, **Start game** disabled, "Waiting for more players…".

## 2. Join (phones)

- [ ] **Phone A:** open the join address (or scan the QR code), tap **عربي** first, type the code and the nickname `سارة`, **Join game**.
  Expect: Arabic, right-to-left, "أنت معنا، سارة!"; the code stays left-to-right.
- [ ] **Phone B:** same in English, nickname `Ben`. Try a **wrong code** first: expect a clear error, not a crash.
- [ ] **Phone C:** log in at `/login` as `test@example.com` **first**, then join. Try the nickname `ben` (lower case): expect "nickname taken"
  (nicknames are unique per room, ignoring case). Then join as `Cy`. Expect: it joins as that account.
- [ ] Host screen: each phone appears **without a reload**, the count reads "3 of 3 …", rows fade in. Names stay left-to-right in the Arabic row.
- [ ] Lock phone B's screen for ~10 s: its row goes grey with **Disconnected**; unlock: it returns by itself, not ready-state lost.
- [ ] Host: **✕** on a row removes that player; that phone goes to `/join` with a message. Rejoin it.

## 3. Ready and Start

- [ ] Tap **I'm ready** on A and B. Expect: "2 of 3 ready", **Start game** still disabled ("Unlocks when everyone taps Ready.").
- [ ] Tap it again on A: it un-readies ("Tap again if you need a minute").
- [ ] Everyone ready: **Start game** enables. Press it. Expect: host shows question 1, all three phones move to the question at the same moment.
  Pressing Start twice quickly starts one game.

## 4. A question (host and phones)

On the **host**:
- [ ] "Question 1 of 5", progress dots (one wide coral pill), the category chip in its tint, the question in English with the Arabic line under it,
  2×2 answers each with its shape (triangle, circle, square, diamond) and the Arabic text on the other side, the ring draining from full, turning
  coral under 5 s.
- [ ] "0 of 3 answered", three avatars at 40%. As phones answer, each gets a teal tick and the count goes up.
- [ ] Nothing scrolls. Resize the window from 1280×720 to full screen: still nothing scrolls.

On each **phone**:
- [ ] Question number (`Q 1 / 5` / `س 1 / 5`), score chip, timer bar, the question and four tiles in **that player's own language**.
- [ ] Tap one tile: it locks at once (no confirm). Expect the tilted tile, "Answer locked in", pulsing dots, "N of 3 answered".
- [ ] Tap again / tap another tile: nothing changes (one answer per question).

## 5. Reveal and scoring

- [ ] Answer on all three phones, mixing right and wrong. Expect: the reveal happens **immediately** when the last one answers.
- [ ] Host: wrong tiles fade to 35%, the correct tile pops with a white "Correct" badge, mini avatars on each tile show who picked it, the navy scoreboard
  shows rank, name, total and "+100" counting up (the leader's row lighter with a sun rank number), "N of 3 got it right", "Next question in 5 s" with a draining bar.
- [ ] Phones: **Correct!** on teal with "+100", or **Not quite!** («ليس هذه المرة!») on cream with "+0", each with "You're 1st · 100 pts" and the correct answer.
- [ ] After 5 s the next question starts by itself. **Next question** on the host skips the wait (click it twice fast: one skip only).
- [ ] Question 2: **do not answer on phone B**. Expect: when the timer ends (about half a second after 0) the reveal shows, phone B says **Time's up!** («انتهى الوقت!»)
  with the correct answer, nothing scored for B.
- [ ] Question 3: press **Skip timer** on the host before anyone answers. Expect: the reveal now.
- [ ] Scores add up: after each question every total is 100 × its correct answers.

## 6. Reconnecting in the middle of a game

- [ ] During a question, **reload the host**: same question, the right time left, the answers so far still ticked.
- [ ] During a question, **reload phone A** before answering: same question, the timer bar continuing from the right place. Answer: it counts.
- [ ] Reload phone B **after** it answered: it shows its locked-in tile, not the question again.
- [ ] Turn phone C's Wi-Fi off during a question, answer on A and B: the reveal comes at the timer's end without waiting for C. Turn Wi-Fi on before the timer
  ends and answer on C: it still counts.
- [ ] Stop `reverb:start` for ~10 s: every screen shows "Reconnecting…" and recovers on its own.
- [ ] Stop the queue worker (keep the host screen open) and play a question: the game still advances, a fraction of a second late.

## 7. The end: results

Finish the game (5 questions). Try to end with a **tie** between two players if you can (answer the same number right).

On the **host**:
- [ ] Navy screen with confetti, "<Name> wins!" with the Arabic line in coral («الفوز من نصيب …»), the podium **2 · 1 · 3** whose bars grow third, second, first, then the crown drops on first.
  With a tie for first: both stand on the first step, the headline names both ("A & B win!"). Three-way tie: "It's a tie!".
- [ ] **Reload the host**: the results are still there (it does not jump to *Set up your game*).
- [ ] Nothing scrolls at 1280×720 or at full screen.
- [ ] Sound: the speaker button is **off** by default. Turn it on and replay a game's first question if you like: question start, a tick for the last 5 seconds, a chime at the reveal,
  a fanfare at the podium. Reload: it stays on. Off again: silence. Phones never make sound.

On each **phone**:
- [ ] A big tilted rank tile (sun 1st, teal 2nd, coral 3rd and below) with "2nd" (Arabic: just the number), a line such as "So close, Ben!" (Arabic equivalent),
  "700 points · 4 of 5 correct", the leaderboard with **your own row outlined** and "(you)".
- [ ] "Stay here. If the host starts another round, you're in." and **Leave room**.

## 8. Save your score

- [ ] **Phones A and B (guests)** see the card **"Save your score"**: "Log in or sign up to save this game to your profile."
- [ ] Phone A: **Sign up**, create an account (any email, a long password). Expect: you land **back on the results page**, and the card now says **"Saved to your profile"**.
- [ ] Phone B: **Sign up** too (a different email), or **Log in** with an account you made earlier. Expect the same.
- [ ] **Phone C** (logged in from the start) already shows **"Saved to your profile"**.
- [ ] On a phone, open `/me/games` from the card's "See my games" link. Expect: the totals (games played 1, wins, correct answers %), and one card with the
  date, categories, rank, score, "N of 5" and "Players: 3". Also check the **My games** link in the dashboard's user menu (bottom of the sidebar) and in the landing page header.
- [ ] A guest who does **not** log in: `/me/games` sends them to log in; nothing about their game is listed.
- [ ] Switch the language to Arabic: the page is right-to-left, dates have Arabic month names, numbers stay 0-9.

## 9. Play again

- [ ] On the host: **Play again**. Expect: the host is taken to a **new lobby with a new code**, with the players who were connected (not ready, scores 0), and **every phone moves to the
  new lobby by itself**, still in its own language.
- [ ] Turn one phone off before pressing Play again: it is **not** copied (it can rejoin with the new code).
- [ ] Ready up everyone and play one question to be sure the new room works.
- [ ] From another finished game: **New game** goes to *Set up your game*. **Back to home** goes to the landing page.
- [ ] Press Play again twice quickly: one new room.

## 10. Leaving and closing

- [ ] A phone on the results screen: **Leave room** goes to the landing page. Its saved result is still in `/me/games` (logged-in players).
- [ ] In a lobby: **Leave room** removes the player from the host's list at once.
- [ ] Host **Close room** (lobby or mid-game, with the confirm): phones show "The host closed this room." and go home; the host goes to *Set up your game*.

## 11. Admin (laptop, `/admin`)

- [ ] **Dashboard**: *Active rooms right now* (lobbies, playing, players connected: check during a game), *Games played per day* (today shows the games you just finished),
  *Most-played categories*, *Questions most often answered wrong* with "% correct".
- [ ] **Questions** list: new columns **Times played** and **% correct**; sort by each.
- [ ] Run `php artisan lamma:prune`: it prints "Closed N abandoned lobbies, cleared M expired guest tokens." Leave a lobby open for 6+ hours (or change its `created_at` in the
  database) and run it again: that lobby is finished and its phones were told.

## 12. Accessibility pass (HANDOFF section 8)

Keyboard (laptop, phone screens in a desktop browser):
- [ ] **Tab** through the join screen, a question, the reveal and the results screens: focus order follows reading order, and every button and link shows the 3px sun focus ring.
- [ ] Answer a question with **Enter/Space** on a focused tile.
- [ ] Nothing is reachable only with a mouse (Play again, New game, Next question, Skip timer, sound toggle, Log in, Sign up, Leave room).

Screen reader (VoiceOver on iPhone or TalkBack on a phone, NVDA on the laptop):
- [ ] Answer buttons read as "Answer B: Mars"; the shapes are silent.
- [ ] Phone: hears "Question 3 of 10", "Answer locked in", "Correct, plus 100 points" or "Not quite, the answer was Mars" / "Time's up, the answer was Mars".
- [ ] The countdown is spoken at **10 s and 5 s only**, not every second.
- [ ] Host lobby: hears "<Name> joined" / "<Name> left". Host game: "Question 3 of 5", then "The correct answer is …".
- [ ] The sound button reads "Sounds, toggle button, pressed / not pressed".

Colour and motion:
- [ ] Turn on **Reduce motion** in the operating system: question and results entrances become fades, nothing pulses, the sticker buttons do not slide, "+100" shows its final value.
- [ ] Zoom the phone page to 200%: still usable.
- [ ] The automated contrast check (`php artisan test --filter=AccessibilityTest`) passes: every text/background token pair meets WCAG AA.

## Sign-off

| Area | Result | Notes |
| --- | --- | --- |
| 1 Create room | | |
| 2 Join | | |
| 3 Ready and start | | |
| 4 A question | | |
| 5 Reveal and scoring | | |
| 6 Reconnecting | | |
| 7 Results | | |
| 8 Save your score | | |
| 9 Play again | | |
| 10 Leaving and closing | | |
| 11 Admin | | |
| 12 Accessibility | | |

Tester: ______________  Date: ______________  Build / commit: ______________
