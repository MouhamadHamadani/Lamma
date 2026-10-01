# Running and testing the real-time lobby

The lobby is live over Laravel Reverb (websockets). Three things have to be running: the web app, Reverb, and (for a phone) built front-end assets.

## What runs

| Process | Command | Needed for |
| --- | --- | --- |
| Web app | `php artisan serve` (127.0.0.1:8000) | everything |
| Reverb | `php artisan reverb:start` (0.0.0.0:8080) | the live lobby |
| Vite | `npm run dev` | CSS/JS on your laptop (not on phones, see below) |
| Queue worker | `php artisan queue:listen --tries=1 --timeout=0` | **not needed yet** |

`composer dev` starts all four in one terminal. The lobby events are `ShouldBroadcastNow`: they are sent from the web request that caused them, so no queue worker is involved. The worker is for the question timers in Phase 5; leave it running anyway.

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
