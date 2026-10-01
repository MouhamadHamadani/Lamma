# Running and testing the real-time lobby and the game

The lobby and the game are live over Laravel Reverb (websockets). Three things have to be running: the web app, Reverb, and (for a phone) built front-end assets. For a game, also keep the queue worker running (see below).

## What runs

| Process | Command | Needed for |
| --- | --- | --- |
| Web app | `php artisan serve` (127.0.0.1:8000) | everything |
| Reverb | `php artisan reverb:start` (0.0.0.0:8080) | the live lobby |
| Vite | `npm run dev` | CSS/JS on your laptop (not on phones, see below) |
| Queue worker | `php artisan queue:listen --tries=1 --timeout=0 --sleep=1` | the question timers (reveal, next question) |

`composer dev` starts all four in one terminal (its queue worker already checks every second). The game events are `ShouldBroadcastNow`: they are sent from the web request that caused them. What the queue does is the *timing*: when a question starts, a delayed `RevealQuestion` job is queued for its deadline, and after the reveal an `AdvanceQuestion` job for 5 seconds later.

**The game keeps moving without a worker as long as the host screen is open.** The host screen calls `tick()` at the moment something is due (and polls every 2 s), and every transition is idempotent and locked, so the worker, the host screen and a double click can all fire without doing anything twice. With the worker stopped *and* the host screen closed, nothing reveals the question: phones just sit on the answered screen.

Without Reverb the lobby pages still load, but they show the "Reconnecting…" card and nothing updates.

## One laptop, two browsers

A guest is identified by a cookie, and cookies are per host name. So use two host names for the same server:

1. Host: `http://localhost:8000` — log in (`english@example.com` / `password`), create a room.
2. Player: `http://127.0.0.1:8000/join` — a different cookie jar, so a real guest. (Or a private window.)
3. Join, tap **I'm ready!**, watch the host screen update, press **Start game**.

The page connects Echo to the host name it was opened on, so `127.0.0.1` works without any configuration.

## One laptop, two phones (same Wi-Fi)

Laptop Wi-Fi address used below: `192.168.18.87` (check yours with `ipconfig`, "Wi-Fi" → IPv4).

1. **Serve the app on the network.** `composer dev` binds to 127.0.0.1 only, so start the processes yourself:

   ```bash
   php artisan serve --host=0.0.0.0 --port=8000
   php artisan reverb:start            # already 0.0.0.0:8080
   php artisan queue:listen --tries=1 --timeout=0
   ```

2. **Use built assets, not the Vite dev server.** Vite's address in `public/hot` is `[::1]:5174`, which a phone cannot reach.

   ```bash
   # stop `npm run dev`, then
   rm public/hot
   npm run build          # run it again after any JS/CSS change
   ```

3. **`.env`.** The defaults already work from a phone:

   ```env
   APP_URL=http://192.168.18.87:8000   # optional, but keeps generated URLs on the LAN address
   REVERB_HOST="localhost"             # see below
   REVERB_PORT=8080
   REVERB_SCHEME=http
   ```

   `REVERB_HOST` is where the *server* reaches Reverb and (via `VITE_REVERB_HOST`, baked in by `npm run build`) where the *browser* connects. In the browser a loopback value (`localhost`, `127.0.0.1`) is replaced by the address the page was opened on, so a phone on `http://192.168.18.87:8000` connects to `ws://192.168.18.87:8080`. If you would rather be explicit, set `REVERB_HOST="192.168.18.87"` and run `npm run build` again. Never put a real domain here unless that is where Reverb listens.

4. **Windows firewall** (Command Prompt as administrator, once). Allow the two ports on a private network:

   ```bat
   netsh advfirewall firewall add rule name="Lamma dev" dir=in action=allow protocol=TCP localport=8000,8080 profile=private
   ```

5. **Open the host screen by the LAN address** on the laptop: `http://192.168.18.87:8000/host/CODE`. The join address and the QR code are built from the address you opened, so opening it as `localhost` would put `localhost` in the QR code.

6. **Phones**: scan the QR code (or open `http://192.168.18.87:8000/join` and type the code). Pick a nickname, join, tap **I'm ready!**.

### What to check

- Each phone appears on the host screen straight away, without a reload; the count reads "N of N ready".
- Lock a phone's screen or switch Wi-Fi off: its row greys out and shows "Disconnected"; it is dropped after 30 seconds, and comes back untouched if the phone returns in time.
- Remove a player with the ✕ on their row: that phone goes to `/join` with a message.
- Start game is disabled until every connected player is ready; pressing it moves the host and both phones to "Get ready…".
- Close room: phones show "The host closed this room." and go to the home page.
- Stop `reverb:start` for a few seconds: every screen shows "Reconnecting…" and recovers by itself when it is back (pusher-js retries with back-off, so allow up to about 30 seconds).

### If a phone shows "Reconnecting…" forever

- The phone cannot reach port 8080: firewall rule missing, or the laptop is on a different network profile ("Public" blocks it).
- Reverb is not running: `php artisan reverb:start --debug` prints every connection.
- The page was opened by an address other than the one Reverb is reachable on (for example a VPN address).
- The assets are stale: run `npm run build` after changing `.env`.

## Playing a whole game

Two origins give two cookie jars (see "One laptop, two browsers"). With the host on `http://localhost:8000` and a phone-sized window on `http://127.0.0.1:8000`:

1. Host: create a room (try 5 questions, 10 s, language **Both**), then on the phone join with a nickname and tap **I'm ready!**. More phones: another browser profile or a private window per player.
2. Host: **Start game**. The host shows question 1 (English with the Arabic line under it, the timer ring, "0 of 1 answered"); the phone shows the question with a timer bar and four tiles.
3. Phone: tap an answer. It locks at once (no confirm) and shows "Answer locked in". When every connected player has answered, the reveal comes immediately; otherwise it comes when the timer ends (a half-second grace is allowed for slow phones).
4. Reveal: the host shows the correct tile (others faded, mini avatars on what each player picked), the navy scoreboard re-orders and "+100" counts up, with a 5 s "Next question in" bar. **Next question** skips the wait; **Skip timer** (on the question screen) shows the answer now. The phone shows "Correct!" (teal), "Not quite!" or "Time's up!" with its rank, score and the correct answer.
5. After the last question the host shows "That's the game!" with the final scores and the phone "You finished 1st · 300 pts". The results screens are the next phase.

### What to check

- Reload the host or a phone in the middle of a question: it comes back on the same question with the right time left (and the phone still shows its own answer if it gave one).
- Switch a phone's Wi-Fi off during a question, answer on the others: the reveal does not wait for the disconnected one. Switch it back on before the timer ends and it can still answer.
- Let a question run out with nobody answering: the host reveals after the timer (nothing is scored), phones show "Time's up!".
- Arabic phone (use the language toggle on `/join` before joining): question, tiles, result and "You're 1st" are in Arabic and right-to-left; the nickname, score and timer stay left-to-right.
- Resize the host window from 1280×720 to 1920×1080: nothing scrolls. On a short window the scoreboard shows 5 players; taller windows show up to 10.
- Stop the queue worker (or never start it) with the host screen open: the game still advances, a fraction of a second later than with a worker.
