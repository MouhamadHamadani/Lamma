# Manual test plan: one laptop, three phones, then the live domain

A full run of Lamma by hand, start to finish. It takes about 60 minutes. Tick each box as you go and write down anything that
differs from "Expect". For how to start the servers and put phones on the laptop's network, see
[realtime-testing.md](realtime-testing.md); this file assumes that works. The last part (section 15) is the same game on the **live domain**,
after a deploy ([deployment.md](deployment.md)).

## What the automated tests already prove

The right-hand column says **Auto** when a test already checks that step. It does **not** replace you where it says *phone*: those steps
depend on a real phone, a real socket, a real screen reader or your eyes. You can skim an Auto step and spend your time on the blank ones.

| Mark | Meaning |
| --- | --- |
| **Auto: X** | covered by test X (`tests/Feature/...` run by `composer test`, or `tests/Browser/...` run by `composer test:browser`) |
| *phone* | automated for the logic, but worth one look on a real phone |
| (blank) | by hand only |

`FullGameTest` (browser) plays a whole game in three real Chromium windows (the host's big screen, an Arabic guest on a 390x844 phone, an
English logged-in player), by clicking: log in, join, Ready, Start, answer right and wrong, reveal, three questions, podium, results. It runs
**without Reverb**: the screens follow by polling, so it proves the game works when the socket is down. It cannot prove the socket itself, so
every real-time step (a name appearing without a reload, the "Reconnecting…" card) is still yours. `SmokeTest` opens every public and account page in
English and Arabic, on a phone and a laptop, with an accessibility audit. `HostScreensTest` checks that no host screen scrolls at 1280x720 and 1920x1080.

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

| ☐ | Step and what to expect | Auto |
| --- | --- | --- |
| ☐ | Log in as the host. **Host a game** opens *Set up your game*. | FullGameTest (login), CreateRoomTest |
| ☐ | Pick **at least two categories**, **5 questions**, **10 s**, **Mixed**, language **Both**. The summary card updates live; with no category picked, *Create room* is disabled with "Pick at least one category." | CreateRoomTest |
| ☐ | The screen does not scroll at 1280×720 or at full screen (it tightens up on short laptops). | HostScreensTest |
| ☐ | **Create room.** The lobby shows the room code (big tilted tiles), a QR code, "Go to <address>/join and enter this room code", the same line in Arabic under it, **Start game** disabled, "Waiting for more players…". | HostLobbyTest |
| ☐ | The QR code, scanned by a real phone, opens the join page with the code filled in. | |

## 2. Join (phones)

| ☐ | Step and what to expect | Auto |
| --- | --- | --- |
| ☐ | **Phone A:** open the join address (or scan the QR code), tap **عربي** first, type the code and the nickname `سارة`, **Join game**. Arabic, right-to-left, "أنت معنا، سارة!"; the code stays left-to-right. | FullGameTest (Arabic guest joins, `dir=rtl`) *phone* |
| ☐ | **Phone B:** same in English, nickname `Ben`. Try a **wrong code** first: a clear error, not a crash. | JoinRoomTest |
| ☐ | **Phone C:** log in at `/login` as `test@example.com` **first**, then join. Try the nickname `ben` (lower case): "nickname taken" (unique per room, ignoring case). Then join as `Cy`: it joins as that account. | RoomJoinerTest, FullGameTest (logged-in player joins) |
| ☐ | Host screen: each phone appears **without a reload**, the count reads "3 of 3 …", rows fade in. Names stay left-to-right in the Arabic row. | |
| ☐ | Lock phone B's screen for ~10 s: its row goes grey with **Disconnected**; unlock: it returns by itself, ready-state kept. | |
| ☐ | Host: **✕** on a row removes that player; that phone goes to `/join` with a message. Rejoin it. | RoomRosterTest, PlayerLobbyTest |
| ☐ | A phone that missed the socket still moves on when the game starts (the lobby polls every 5 s). | FullGameTest |

## 3. Ready and Start

| ☐ | Step and what to expect | Auto |
| --- | --- | --- |
| ☐ | Tap **I'm ready** on A and B: "2 of 3 ready", **Start game** still disabled ("Unlocks when everyone taps Ready."). | FullGameTest (Ready, Start unlocks), HostLobbyTest |
| ☐ | Tap it again on A: it un-readies. | RoomRosterTest |
| ☐ | Everyone ready: **Start game** enables. Press it. The host shows question 1, all phones move to the question at the same moment. | FullGameTest *phone* |
| ☐ | Pressing Start twice quickly starts one game. | GameEngineTest |

## 4. A question (host and phones)

| ☐ | Step and what to expect | Auto |
| --- | --- | --- |
| ☐ | **Host:** "Question 1 of 5", progress dots (one wide coral pill), the category chip in its tint, the question in English with the Arabic line under it, 2×2 answers each with its shape (triangle, circle, square, diamond) and the Arabic text on the other side, the ring draining from full, turning coral under 5 s. | HostGameTest, GameComponentsTest |
| ☐ | **Host:** "0 of 3 answered", three avatars at 40%. As phones answer, each gets a teal tick and the count goes up. | HostGameTest |
| ☐ | Nothing scrolls on the host, from 1280×720 to full screen. | HostScreensTest |
| ☐ | **Phone:** question number (`Q 1 / 5` / `س 1 / 5`), score chip, timer bar, the question and four tiles in **that player's own language**. | FullGameTest (Arabic and English phones), PlayerGameTest |
| ☐ | Tap one tile: it locks at once (no confirm). Tilted tile, "Answer locked in", pulsing dots, "N of 3 answered". | FullGameTest, PlayerGameTest |
| ☐ | Tap again / tap another tile: nothing changes (one answer per question). | AnswerTest |
| ☐ | A phone never scrolls sideways. | FullGameTest, SmokeTest |

## 5. Reveal and scoring

| ☐ | Step and what to expect | Auto |
| --- | --- | --- |
| ☐ | Answer on all three phones, mixing right and wrong: the reveal happens **immediately** when the last one answers. | FullGameTest, RevealAdvanceTest |
| ☐ | **Host:** wrong tiles fade to 35%, the correct tile pops with a white "Correct" badge, mini avatars show who picked it, the navy scoreboard shows rank, name, total and "+100" counting up, "N of 3 got it right", "Next question in 5 s" with a draining bar. | HostGameTest, HostScreensTest (fits the screen) |
| ☐ | **Phones:** **Correct!** on teal with "+100", or **Not quite!** («ليس هذه المرة!») on cream with "+0", each with "You're 1st · 100 pts" and the correct answer. | FullGameTest (Arabic «صحيح», English "Not quite"), PlayerGameTest |
| ☐ | After 5 s the next question starts by itself. **Next question** on the host skips the wait (click it twice fast: one skip only). | FullGameTest (by itself), RevealAdvanceTest |
| ☐ | Question 2: **do not answer on phone B**. When the timer ends (about half a second after 0) the reveal shows; phone B says **Time's up!** («انتهى الوقت!») with the correct answer, nothing scored for B. | AnswerTest, RevealAdvanceTest |
| ☐ | Question 3: press **Skip timer** on the host before anyone answers: the reveal now. | RevealAdvanceTest |
| ☐ | Scores add up: after each question every total is 100 × its correct answers. | FullGameTest (200 and 100 at the end), GameSimulationTest |

## 6. Reconnecting in the middle of a game

| ☐ | Step and what to expect | Auto |
| --- | --- | --- |
| ☐ | During a question, **reload the host**: same question, the right time left, the answers so far still ticked. | GamePayloadTest, HostGameTest |
| ☐ | During a question, **reload phone A** before answering: same question, the timer bar continuing from the right place. Answer: it counts. | PlayerGameTest |
| ☐ | Reload phone B **after** it answered: its locked-in tile, not the question again. | PlayerGameTest |
| ☐ | Turn phone C's Wi-Fi off during a question, answer on A and B: the reveal comes at the timer's end without waiting for C. Turn Wi-Fi on before the timer ends and answer on C: it still counts. | AnswerTest (logic only) |
| ☐ | Stop `reverb:start` for ~10 s: every screen shows "Reconnecting…" and recovers on its own. | |
| ☐ | Stop the queue worker (keep the host screen open) and play a question: the game still advances, a fraction of a second late. | FullGameTest (no queue worker, only the host tick) |

## 7. The end: results

Finish the game (5 questions). Try to end with a **tie** between two players if you can.

| ☐ | Step and what to expect | Auto |
| --- | --- | --- |
| ☐ | **Host:** navy screen with confetti, "<Name> wins!" with the Arabic line in coral («الفوز من نصيب …»), the podium **2 · 1 · 3** whose bars grow third, second, first, then the crown drops on first. With a tie for first: both on the first step, the headline names both. Three-way tie: "It's a tie!". | FullGameTest (winner and two steps), ResultsTest, HostResultsTest |
| ☐ | **Reload the host**: the results are still there (it does not jump to *Set up your game*). | HostResultsTest |
| ☐ | Nothing scrolls at 1280×720 or at full screen. | HostScreensTest |
| ☐ | Sound: the speaker button is **off** by default. Turn it on: question start, a tick for the last 5 seconds, a chime at the reveal, a fanfare at the podium. Reload: it stays on. Phones never make sound. | SoundsTest (the switch only) |
| ☐ | **Phone:** a big tilted rank tile (sun 1st, teal 2nd, coral 3rd and below), a line such as "So close, Ben!", "700 points · 4 of 5 correct", the leaderboard with **your own row outlined** and "(you)". | FullGameTest (rank 1 and 2), PlayerResultsTest |
| ☐ | "Stay here. If the host starts another round, you're in." and **Leave room**. | PlayerResultsTest |

## 8. Save your score

| ☐ | Step and what to expect | Auto |
| --- | --- | --- |
| ☐ | **Phones A and B (guests)** see the card **"Save your score"**. | FullGameTest, PlayerResultsTest |
| ☐ | Phone A: **Sign up**, create an account. You land **back on the results page**, and the card says **"Saved to your profile"**. | PlayerResultsTest, ClaimGuestResultsTest |
| ☐ | Phone B: **Sign up** too (a different email), or **Log in** with an account you made earlier: same. | PlayerResultsTest |
| ☐ | **Phone C** (logged in from the start) already shows **"Saved to your profile"**. | FullGameTest |
| ☐ | `/me/games` (card link): totals (played 1, wins, correct %), one card with date, categories, rank, score, "N of 5", "Players: 3". The **My games** link is in the account menu (top right) and on the landing page. | FullGameTest (played = 1), MyGamesTest, AccountAreaTest |
| ☐ | A guest who does **not** log in: `/me/games` sends them to log in; nothing about their game is listed. | MyGamesTest |
| ☐ | Switch to Arabic: right-to-left, Arabic month names, numbers stay 0-9. | MyGamesTest, SmokeTest |

## 9. Play again

| ☐ | Step and what to expect | Auto |
| --- | --- | --- |
| ☐ | **Play again** on the host: a **new lobby with a new code**, with the players who were connected (not ready, scores 0), and **every phone moves there by itself**, still in its own language. | RoomManagerTest, ResultsTest, PlayerResultsTest *phone* |
| ☐ | Turn one phone off before Play again: it is **not** copied (it can rejoin with the new code). | RoomManagerTest |
| ☐ | Ready up everyone and play one question to be sure the new room works. | |
| ☐ | **New game** goes to *Set up your game*. **Back to home** goes to the landing page. | HostResultsTest |
| ☐ | Press Play again twice quickly: one new room. | RoomManagerTest |

## 10. Leaving and closing

| ☐ | Step and what to expect | Auto |
| --- | --- | --- |
| ☐ | A phone on the results screen: **Leave room** goes to the landing page. Its saved result is still in `/me/games`. | PlayerResultsTest |
| ☐ | In a lobby: **Leave room** removes the player from the host's list at once. | RoomRosterTest *phone* |
| ☐ | Host **Close room** (lobby or mid-game, with the confirm): phones show "The host closed this room." and go home; the host goes to *Set up your game*. | HostLobbyTest, PlayerLobbyTest |

## 11. Admin (laptop, `/admin`)

| ☐ | Step and what to expect | Auto |
| --- | --- | --- |
| ☐ | Log in as the admin. A switched-off admin (`is_active` = 0) cannot. | GuardSeparationTest, ProductionTest |
| ☐ | **Dashboard**: *Active rooms right now*, *Games played per day*, *Most-played categories*, *Questions most often answered wrong* with "% correct". | DashboardWidgetsTest |
| ☐ | **Questions** list: columns **Times played** and **% correct**; sort by each. The seeded 400 questions (8 categories) are there. | DashboardWidgetsTest, ContentTest |
| ☐ | `php artisan lamma:prune` prints "Closed N abandoned lobbies, cleared M expired guest tokens." A lobby older than 6 hours is finished and its phones told. | PruneTest |

## 12. Accessibility pass (HANDOFF section 8)

| ☐ | Step and what to expect | Auto |
| --- | --- | --- |
| ☐ | **Tab** through the join screen, a question, the reveal and the results: focus order follows reading order, every button and link shows the 3px sun focus ring. | AccessibilityTest (ring rules) |
| ☐ | Answer a question with **Enter/Space** on a focused tile. | |
| ☐ | Nothing is reachable only with a mouse (Play again, New game, Next question, Skip timer, sound toggle, Log in, Sign up, Leave room, the account menu). | SmokeTest (menu opens with the keyboard, Escape closes) |
| ☐ | **Screen reader** (VoiceOver, TalkBack, NVDA): answer buttons read "Answer B: Mars"; the shapes are silent. | AccessibilityTest (names), SmokeTest (axe) |
| ☐ | Phone hears "Question 3 of 10", "Answer locked in", "Correct, plus 100 points" / "Not quite, the answer was Mars" / "Time's up, the answer was Mars". | AccessibilityTest (live regions) |
| ☐ | The countdown is spoken at **10 s and 5 s only**. | AccessibilityTest |
| ☐ | Host lobby: "<Name> joined" / "<Name> left". Host game: "Question 3 of 5", then "The correct answer is …". | HostLobbyTest, HostGameTest |
| ☐ | Turn on **Reduce motion** in the operating system: entrances become fades, nothing pulses, the sticker buttons do not slide, "+100" shows its final value. | AccessibilityTest (every animation is `motion-safe:`) |
| ☐ | Zoom the phone page to 200%: still usable. | |
| ☐ | Every text/background colour pair meets WCAG AA. | AccessibilityTest, SmokeTest (axe contrast) |

## 13. The account area (new)

| ☐ | Step and what to expect | Auto |
| --- | --- | --- |
| ☐ | Log in: you land on **My games** (or the page you were trying to reach). There is no dashboard. | AccountAreaTest |
| ☐ | The **account menu** (initial + name, top right) opens with the mouse and the keyboard, **Escape** closes it and returns focus to its button; it lists My games, Host a game, Settings, Log out. On the landing page, My games and Settings too. | SmokeTest, AccountAreaTest |
| ☐ | **Settings > Profile**: change name and email, **Save**: "Profile updated." Change the language to the other one and Save: the whole page reloads right-to-left or left-to-right, and the language switcher on public pages agrees. | AccountAreaTest |
| ☐ | **Settings > Security** (asks for your password first): change the password with a wrong current password (refused) and a right one ("Password updated."). | AccountAreaTest, PasswordConfirmationTest |
| ☐ | **Two-factor**: Enable 2FA, scan the QR code with an authenticator app (or type the setup key, the copy button copies it), enter the 6-digit code (paste works), see the recovery codes, regenerate them. Log out and in again: the code page asks for the code; "use a recovery code" works. Disable 2FA. | AccountAreaTest (logic and markup), TwoFactorChallengeTest *phone* |
| ☐ | **Passkeys**: Add passkey on a device that supports it, sign in with it, remove it (a dialog asks first). | AccountAreaTest (list and remove) |
| ☐ | **Delete account**: the button opens a dialog asking for the password; **Escape** or Cancel closes it; the wrong password is refused; the right one deletes the account and goes to the landing page. Other players of the games you played still see those games in **their** My games. A lobby you were hosting is closed. | AccountAreaTest, SmokeTest (dialog) |
| ☐ | There is no Appearance page and no dark mode (set the OS to dark: Lamma stays light). | AccountAreaTest |

## 14. Error pages, emails and maintenance (new)

| ☐ | Step and what to expect | Auto |
| --- | --- | --- |
| ☐ | An unknown address shows the Lamma **404** page (cream, the big tile, "Back to home", the language switch), in English and in Arabic. | ErrorPagesTest, SmokeTest |
| ☐ | `/host/ZZZZZZ` and `/play/ZZZZZZ` (a room that does not exist) show "That room doesn't exist" with **Join a game**. | ErrorPagesTest |
| ☐ | Leave a form open for longer than the session lifetime, then submit: the **419** "Page expired" page with "Refresh and try again". | ErrorPagesTest |
| ☐ | Too many wrong passwords or room codes: the **429** / "Too many attempts" message. | ProductionTest, JoinRoomTest |
| ☐ | **Forgot password** with a real address: the email arrives within a minute **in the account's language** (Arabic: right-to-left, «لمّة» header), the button works, the link expires after 60 minutes. | ResetPasswordEmailTest (content), the delivery is yours |
| ☐ | `php artisan down --render="errors::503"`: every page shows the bilingual maintenance page with its own styling; `php artisan up` brings the site back. | ErrorPagesTest |

## 15. On the live domain (after a deploy)

Follow [deployment.md](deployment.md) section 15 with real phones on **mobile data**, and also tick:

| ☐ | Step and what to expect | Auto |
| --- | --- | --- |
| ☐ | `php artisan lamma:doctor` on the server: no **FAIL** rows. Read every WARN. | DoctorTest |
| ☐ | `https://<domain>` has a valid certificate; `http://<domain>` redirects to https; `www.<domain>` redirects to the bare domain. | DeployKitTest (the config) |
| ☐ | A full game with the three phones: names appear without a reload, the phones move on by themselves (the **WebSocket works through nginx**: no "Reconnecting…" card stays). | |
| ☐ | Lock a phone for 20 s and unlock: it reconnects and lands on the right screen. | |
| ☐ | Password-reset email arrives, in the right language. | |
| ☐ | `/_components` is a 404. `/admin` asks for the admin login; the test accounts (`test@example.com`) do not exist. | ProductionTest, DoctorTest |
| ☐ | A push to `main` with green tests deploys by itself (Actions: `tests`, then `deploy`); the site shows the 503 page for a short moment, then the new version. | DeployKitTest (the workflow and the script) |
| ☐ | The nightly backup file exists (`ls /var/backups/lamma`) and a restore into an empty database works. | |
| ☐ | The placeholder logo, icons and sounds are replaced by the official files (the doctor stops warning). | DoctorTest |

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
| 13 Account area | | |
| 14 Errors, emails, maintenance | | |
| 15 Live domain | | |

Tester: ______________  Date: ______________  Build / commit: ______________
