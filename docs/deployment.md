# Deploying Lamma to a Hetzner server (Ubuntu 24.04)

One server runs everything. This guide takes a brand-new server to a live site, and then to "every push to `main` deploys itself".
Read it top to bottom the first time. Copy-paste the commands; the words in `UPPER CASE` or `lamma.example` are yours to replace.

> **Placeholders you must replace.** The domain is not decided yet, so every file uses `lamma.example`. When you pick the domain, replace it in:
> `.env` (from `.env.production.example`: `APP_URL`, `REVERB_HOST`, `MAIL_FROM_ADDRESS`), `deploy/nginx/lamma.conf` (6 places),
> `deploy/nginx/lamma-http-only.conf` (1 place) and the `certbot` commands below. `lamma:doctor` warns while `APP_URL` still says `lamma.example`.

## 0. What you need first

- [ ] A **domain** and access to its DNS.
- [ ] A **Hetzner Cloud** server: Ubuntu 24.04, 2 vCPU / 4 GB RAM is plenty for several simultaneous games (e.g. CX22). Turn on Hetzner **Backups** too: they are your second safety net, beside the nightly database dump in section 12.
- [ ] Your **SSH public key** added to the server when you create it.
- [ ] **SMTP details** from a mail provider (host, port, user, password), for password-reset emails.
- [ ] A **GitHub** repository with this code (`main` is the branch that deploys).

```
 phones / laptop ──https──▶ nginx :443 ──▶ PHP-FPM (Laravel, Livewire)        ◀── Supervisor: lamma-worker x2  (queue:work redis)
                              │                                                  Supervisor: lamma-reverb      (reverb:start 127.0.0.1:8080)
                              └──wss /app, /apps──▶ Reverb 127.0.0.1:8080       cron: schedule:run every minute, backup.sh nightly
                 MySQL 8 and Redis listen on this machine only (never on the internet)
```

## 1. First login and a user for the app

DNS first (it takes time to spread): create an **A** record for `lamma.example` (and `www`) pointing at the server's IPv4, and an **AAAA** record for its IPv6.

```bash
ssh root@SERVER_IP
apt update && apt upgrade -y
timedatectl set-timezone UTC

# The one Unix user that owns the code and runs the web app, the queue worker, Reverb, the scheduler and deploys.
adduser --disabled-password --gecos "" lamma
mkdir -p /var/www && chown lamma:lamma /var/www
rsync --archive --chown=lamma:lamma ~/.ssh /home/lamma     # lets you log in as lamma with the same key

# Firewall: only SSH, HTTP and HTTPS are reachable. (MySQL, Redis and Reverb stay on 127.0.0.1.)
ufw allow OpenSSH && ufw allow 80/tcp && ufw allow 443/tcp && ufw --force enable

# Automatic security updates.
apt install -y unattended-upgrades && dpkg-reconfigure -plow unattended-upgrades
```

From now on, log in as `ssh lamma@SERVER_IP` for everything marked `# as lamma`. `lamma` has no `sudo` on purpose: keep a second terminal open as `root` (`ssh root@SERVER_IP`) for the steps marked `# as root`.

## 2. Install the software

```bash
# as root
apt install -y nginx mysql-server redis-server supervisor git unzip curl certbot \
  php8.3-cli php8.3-fpm php8.3-mysql php8.3-redis php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-intl php8.3-bcmath php8.3-gd
apt install -y composer

# Node 22 (the version the build and CI use)
curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
apt install -y nodejs

php -v && composer --version && node -v    # PHP 8.3.x, Composer 2.x, v22.x
```

The PHP extensions are the ones `composer.json`'s dependencies ask for (`intl` for the admin panel, `zip`/`xml` for exports, `mbstring`, `curl`, `openssl`) plus `mysql` and `redis`.

**Player selfies need two more, both already in the line above:** `php8.3-gd` built with **JPEG and WebP** support (Ubuntu's package is; every photo is decoded and
re-encoded as a 256x256 WebP, which also strips all of its metadata) and `fileinfo` (part of `php8.3-common`, used to see what a file really is). No ImageMagick and no
Intervention Image: GD is all that is used. `php artisan lamma:doctor` has a **Player photos** row that fails, naming what is missing, if GD or its WebP/JPEG support or
fileinfo is absent, or the photo folder cannot be written. Check by hand with `php -r 'var_dump(gd_info()["WebP Support"], extension_loaded("fileinfo"));'` (both `bool(true)`).

## 3. The database

```bash
# as root
mysql <<'SQL'
CREATE DATABASE lamma CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'lamma'@'localhost' IDENTIFIED BY 'A-LONG-RANDOM-PASSWORD';   -- avoid $ " ' and spaces in it
GRANT ALL PRIVILEGES ON lamma.* TO 'lamma'@'localhost';
SQL
```

Redis needs nothing: on Ubuntu it listens on `127.0.0.1` only. Check with `redis-cli ping` (it answers `PONG`).

## 4. Get the code

```bash
# as lamma
ssh-keygen -t ed25519 -C "lamma-server" -f ~/.ssh/github_deploy -N ""
cat ~/.ssh/github_deploy.pub
```

On GitHub: the repository > **Settings > Deploy keys > Add deploy key**, paste it, leave "Allow write access" **off**. Then tell git to use it and clone:

```bash
# as lamma
printf 'Host github.com\n  IdentityFile ~/.ssh/github_deploy\n  IdentitiesOnly yes\n' >> ~/.ssh/config
chmod 600 ~/.ssh/config
git clone git@github.com:YOUR_ACCOUNT/Lamma.git /var/www/lamma
cd /var/www/lamma
```

## 5. Configure `.env`

```bash
# as lamma, in /var/www/lamma
cp .env.production.example .env
nano .env
```

Fill in: `APP_URL` and `REVERB_HOST` (your domain), `DB_PASSWORD`, the `MAIL_*` values, optionally `GOOGLE_ANALYTICS_ID` (section 17), and `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`
(any random strings: `php -r 'echo bin2hex(random_bytes(16)),PHP_EOL;'`, three times). Leave `ADMIN_*` for section 9. Then:

```bash
chmod 600 .env
```

`APP_KEY` is created in the first deploy (section 8). **Never lose or change it afterwards** (it encrypts cookies and two-factor secrets), and keep a copy of `.env` in your password manager.

## 6. PHP-FPM, nginx and HTTPS

```bash
# as root, in /var/www/lamma
cp deploy/php-fpm/lamma-pool.conf /etc/php/8.3/fpm/pool.d/lamma.conf
rm /etc/php/8.3/fpm/pool.d/www.conf          # the default pool is not needed
systemctl restart php8.3-fpm

# Step 1 of HTTPS: serve only port 80 so certbot can prove you own the domain.
mkdir -p /var/www/certbot
cp deploy/nginx/lamma-http-only.conf /etc/nginx/sites-available/lamma.conf
sed -i 's/lamma.example/YOUR-DOMAIN/g' /etc/nginx/sites-available/lamma.conf
ln -sf /etc/nginx/sites-available/lamma.conf /etc/nginx/sites-enabled/lamma.conf
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

certbot certonly --webroot -w /var/www/certbot -d YOUR-DOMAIN -d www.YOUR-DOMAIN -m YOU@EXAMPLE.COM --agree-tos --no-eff-email
# Renewal is automatic (a systemd timer). Make nginx pick up renewed certificates:
printf '#!/bin/sh\nsystemctl reload nginx\n' > /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
chmod +x /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh

# Step 2: the real site (HTTPS, PHP, the WebSocket proxy).
cp deploy/nginx/lamma.conf /etc/nginx/sites-available/lamma.conf
sed -i 's/lamma.example/YOUR-DOMAIN/g' /etc/nginx/sites-available/lamma.conf
nginx -t && systemctl reload nginx
```

`deploy/nginx/lamma.conf` redirects http to https and `www` to the bare domain, serves `/build` (cached for a year, the file names carry a hash),
`/brand` and `/sounds` (30 days), compresses text, allows 8 MB uploads (question images), runs only `index.php` through PHP-FPM, and proxies `/app` and
`/apps` to Reverb on `127.0.0.1:8080` with the `Upgrade`/`Connection` headers and one-hour read timeouts, so a WebSocket stays open through a whole game.

## 7. Supervisor and cron

Install these **after** the first deploy in section 8 (they need the code installed to run).

```bash
# as root, in /var/www/lamma
cp deploy/supervisor/lamma-worker.conf deploy/supervisor/lamma-reverb.conf /etc/supervisor/conf.d/
sed -i 's/^\[supervisord\]/[supervisord]\nminfds=10000/' /etc/supervisor/supervisord.conf    # many phones = many open connections
systemctl restart supervisor
supervisorctl reread && supervisorctl update
supervisorctl status          # lamma-reverb RUNNING, lamma-worker:lamma-worker_00 and _01 RUNNING

cp deploy/cron/lamma /etc/cron.d/lamma && chmod 644 /etc/cron.d/lamma
mkdir -p /var/backups/lamma && chown lamma:lamma /var/backups/lamma
```

The cron file has two lines: the Laravel scheduler every minute (`php artisan schedule:run`, which runs `lamma:prune` hourly and records the heartbeat that
`lamma:doctor` checks) and the nightly backup (section 12).

## 8. The first deploy

```bash
# as lamma, in /var/www/lamma
php artisan key:generate          # writes APP_KEY into .env. Only on the very first deploy.
bash deploy/deploy.sh
```

`deploy/deploy.sh` is the same script every later deploy uses. In order: maintenance page on (`php artisan down --render="errors::503" --retry=15`),
`git pull --ff-only`, `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`, `php artisan migrate --force`,
`php artisan db:seed --class=ContentSeeder --force` (adds the shipped questions, about 1,200, and the subcategories; it only ever adds what is missing), `storage:link` if missing,
`php artisan optimize`, `queue:restart`, `reverb:restart`, maintenance page off (`php artisan up`), and finally `php artisan lamma:doctor`.
It stops at the first error and brings the site back up. It is safe to run again.

The very first run ends with `lamma:doctor` failing on *Reverb is listening* (and warning about the scheduler), because Supervisor and cron are not installed yet: that is expected, and the site itself is up. Now do section 7 (Supervisor and cron) and run the check again:

```bash
php artisan lamma:doctor
```

Read the table. **Fail** rows must be fixed. **Warn** rows are fine to launch with, but know what they say:
the *scheduler* row warns until the cron job has run `lamma:prune` once (after midnight, or run `php artisan lamma:prune` by hand once);
the *official brand and sound files* row warns until you replace the placeholders (section 14).

## 9. Create the admin

```bash
# as lamma, in /var/www/lamma
nano .env      # set ADMIN_NAME, ADMIN_EMAIL and ADMIN_PASSWORD (12+ characters, upper and lower case, a number and a symbol)
php artisan db:seed --class=AdminSeeder --force
nano .env      # now delete the ADMIN_PASSWORD value again
```

`AdminSeeder` refuses to run in production without a valid email and a strong password, so there is never a default admin. Log in at
`https://lamma.example/admin` (the admin panel is separate from player accounts). To lock an admin out without deleting them, set `is_active` to 0 on their row in
the `admins` table. The two test players (`test@example.com`, `english@example.com`) are never created in production; `lamma:doctor` fails if they exist.

## 10. Deploy automatically from GitHub

`.github/workflows/tests.yml` runs on every push and pull request (a fast job for Pint, PHPStan and the test suite, and a separate job for the browser tests).
`.github/workflows/deploy.yml` runs when that workflow has finished **successfully for a push to `main`**: it connects to the server over SSH and runs
`bash deploy/deploy.sh`. Pull requests and failed test runs never deploy. Two deploys never run at the same time.

1. On the server, as `lamma`, make a key just for GitHub Actions:
   ```bash
   ssh-keygen -t ed25519 -C "github-actions-deploy" -f ~/.ssh/actions_deploy -N ""
   cat ~/.ssh/actions_deploy.pub >> ~/.ssh/authorized_keys
   cat ~/.ssh/actions_deploy        # the PRIVATE key: copy all of it, including the BEGIN and END lines, then delete the file
   rm ~/.ssh/actions_deploy
   ```
2. On GitHub: the repository > **Settings > Secrets and variables > Actions > New repository secret**:

   | Secret | Value |
   |---|---|
   | `SSH_HOST` | the server's IP address or domain |
   | `SSH_USER` | `lamma` |
   | `SSH_PRIVATE_KEY` | the private key from step 1 |
   | `SSH_PORT` | `22` (or your SSH port) |
   | `APP_PATH` | `/var/www/lamma` |
   | `SSH_FINGERPRINT` *(optional, recommended)* | the server's host key fingerprint: `ssh-keyscan -t ed25519 SERVER_IP \| ssh-keygen -lf -` |

3. Push to `main`. Watch **Actions**: `tests` first, then `deploy`. The deploy log is the output of `deploy.sh`, ending with the `lamma:doctor` table.

If a deploy fails, the site is up again on whatever the failed step left (the script runs `php artisan up` on exit). Read the log, fix the cause, push again, or run
`bash deploy/deploy.sh` on the server by hand.

## 11. Logs

| What | Where |
|---|---|
| The app (errors, warnings) | `/var/www/lamma/storage/logs/laravel-YYYY-MM-DD.log`: one file a day, 14 days kept (`LOG_CHANNEL=daily`, `LOG_LEVEL=warning`) |
| Queue worker | `/var/www/lamma/storage/logs/worker.log` (Supervisor rotates it: 10 MB x 5) |
| Reverb | `/var/www/lamma/storage/logs/reverb.log` (same) |
| nginx | `/var/log/nginx/lamma.access.log`, `/var/log/nginx/lamma.error.log` (rotated by the system) |
| PHP-FPM | `/var/log/php8.3-fpm-lamma.log` |
| Backups | `/var/log/lamma-backup.log` |
| Supervisor / MySQL / Redis | `journalctl -u supervisor`, `journalctl -u mysql`, `journalctl -u redis-server` |

```bash
tail -f /var/www/lamma/storage/logs/laravel-$(date +%F).log      # follow today's app log
supervisorctl status                                              # are the worker and Reverb running?
cd /var/www/lamma && php artisan lamma:doctor                     # the one-minute health check
```

## 12. Backups

The nightly job (`deploy/cron/lamma` runs `deploy/backup.sh` at 02:30, which uses `mysqldump`) writes `/var/backups/lamma/lamma-YYYY-MM-DD-HHMM.sql.gz` and deletes dumps older than 7 days.
It reads the database name and password from `.env`, never puts the password on a command line, and fails loudly if a dump comes out nearly empty.

```bash
bash /var/www/lamma/deploy/backup.sh && ls -lh /var/backups/lamma       # try it once now
tail /var/log/lamma-backup.log

# Restore (into an EMPTY database, after "DROP DATABASE lamma; CREATE DATABASE lamma CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"):
gunzip -c /var/backups/lamma/lamma-2026-01-31-0230.sql.gz | mysql -u lamma -p lamma
```

**Player photos are not backed up, on purpose.** `backup.sh` dumps MySQL only. If you add any file-level backup or snapshot of the server's disk, **exclude
`/var/www/lamma/storage/app/public/player-photos`** (for example `rsync -a --exclude 'storage/app/public/player-photos' ...`): the photos can be pictures of children, they are
deleted 24 hours after their game (section 18), and a backup would keep them for as long as the backup lives. A restore brings back players without photos, which is fine: they
show their initial. (Hetzner's server-level Backups copy the whole disk: that is a reason to keep the retention short.)

The dumps live on the same server, so they do not survive losing it. Also keep **Hetzner Backups** on, and copy the folder somewhere else from time to time
(`rsync -a /var/backups/lamma/ you@elsewhere:lamma-backups/`). Test a restore once.

## 13. Rolling back

If a deploy broke something, go back to the previous commit:

```bash
# as lamma, in /var/www/lamma
git log --oneline -5                     # find the commit that worked
git checkout THAT_COMMIT                 # (a detached HEAD is fine)
bash deploy/deploy.sh --no-pull          # rebuild and restart what is checked out, without pulling
```

When the fix is on `main`: `git checkout main && bash deploy/deploy.sh`.
If the bad deploy also ran a **database migration**, the newer tables are still there. Most migrations only add things and the old code ignores them. If one did
not, restore the nightly backup (section 12) or run `php artisan migrate:rollback --step=1` *before* checking out the old commit. Do this on a quiet moment.

## 14. Replacing the placeholder logo, icons and sounds

`public/brand/*`, `public/favicon.*`, `public/apple-touch-icon.png` and `public/sounds/*.wav` are placeholders. Put the official files in place **with the same
file names**, commit, push, deploy. `lamma:doctor` stops warning when a file no longer matches its placeholder (the list of placeholder hashes is in
`config/lamma.php`; you can delete a line there once its file is replaced). nginx caches `/brand` and `/sounds` for 30 days, so people who already visited may
see the old logo for a while: it is not a bug.

## 15. Testing with phones on the live domain

Do this once everything is up and `lamma:doctor` has no fails. You need a laptop and 2-3 phones on **mobile data** (not your Wi-Fi), so you test what real players get.

1. Laptop: open `https://lamma.example`, **Host a game** (log in or sign up first), create a room, 5 questions, in both languages.
2. Phone A: open the address shown on the screen (or scan the QR code), join as a guest in Arabic. Phone B: join in English while logged in.
3. Both appear on the laptop within a second. Tap **Ready** on both, **Start game**. Answer, watch the reveal, scoreboard and podium. Phones follow every step.
4. **WebSockets work** if phones move to the next screen by themselves; if they only move after a manual refresh, nginx is not proxying `/app` (see below).
5. Lock a phone for 20 seconds and unlock it: it should reconnect and land on the right screen.
6. On phone A, **Save your score** > create an account > you land back on the results; **My games** lists the game.
7. Forgot password with a real address: the email arrives (Arabic for an Arabic account), the link works.
8. `https://lamma.example/does-not-exist` shows the Lamma 404 page; `/admin` asks for the admin login.
9. Take the server down for a moment: `php artisan down --render="errors::503"` then `php artisan up`: the maintenance page shows in both languages.
10. **Selfies:** with photos on (the default), join from a phone: the page has a "Take a selfie" step. It opens the front camera (the browser asks once), and the photo appears on
    the host screen. The camera only works over **https**, which the live domain has. See section 18 and the player-photo part of `docs/manual-test-plan.md`.

The full hand test is in `docs/manual-test-plan.md`.

## 16. When something is wrong

| Symptom | Look at |
|---|---|
| `lamma:doctor` says **Reverb is listening: FAIL** | `supervisorctl status`; `tail storage/logs/reverb.log`. Start it: `supervisorctl start lamma-reverb` |
| Phones do not move on by themselves; the browser console shows a failed `wss://` connection | `REVERB_HOST`/`REVERB_PORT=443`/`REVERB_SCHEME=https` in `.env` (then redeploy: `VITE_*` are baked in at build time); the `/app` block in the nginx file; `nginx -t` |
| Game timers never fire | `supervisorctl status` for `lamma-worker`; `QUEUE_CONNECTION=redis` and Redis running (`redis-cli ping`) |
| Password-reset email never arrives | `MAIL_*` in `.env`; the worker is running (mail is queued); `storage/logs/worker.log` |
| 419 "Page expired" for everyone | `SESSION_SECURE_COOKIE=true` needs https; check `APP_URL`; Redis running |
| 502 Bad Gateway | `systemctl status php8.3-fpm`; the socket name in nginx matches `listen =` in the pool file |
| A deploy hangs on maintenance mode | `php artisan up` |
| Disk full | `df -h`; old logs in `storage/logs`; old dumps in `/var/backups/lamma`; `storage/app/public/player-photos` (is `lamma:prune` running every hour? `lamma:doctor` Scheduler row) |
| "We couldn't open the camera" on every phone | The site must be `https://` (a camera is not offered to http pages, except `localhost`); the phone's browser permission for the site; an in-app browser (Instagram, WhatsApp) often has no camera: open it in Safari or Chrome; no `Permissions-Policy` header may forbid `camera` |
| Photos are not shown, only initials | `php artisan storage:link` (the `public/storage` link must exist: `lamma:doctor` Storage link row); `ls storage/app/public/player-photos/rooms`; the nginx `/storage/player-photos/` block |
| `lamma:doctor` says **Player photos: FAIL** | `php8.3-gd` (with WebP) or `fileinfo` missing, or the folder is not writable by the `lamma` user: `apt install php8.3-gd php8.3-common`, `systemctl restart php8.3-fpm` |

After changing `.env`, run `php artisan optimize` (config is cached) and `php artisan queue:restart && php artisan reverb:restart`.

## 17. Google Analytics (optional)

Lamma can count visits with Google Analytics 4. It is **off** until you give it a measurement ID, it only runs in production, and it is built to be private (players can be children and the
pages carry room codes).

**Switch it on**

1. In Google Analytics create a property and a **web data stream** for `https://<your domain>`. Copy its **Measurement ID** (looks like `G-ABC123DEF4`).
2. In `.env` on the server: `GOOGLE_ANALYTICS_ID=G-ABC123DEF4`, then `bash deploy/deploy.sh` (or `php artisan config:cache`).
3. `php artisan lamma:doctor`: the *Google Analytics* row is a **warn** while the ID is empty and a **fail** if it is not in the `G-` + 10 letters or digits form.

Leave it empty to run without analytics: no script, no notice, no cookie.

**What it does and does not do**

- **Consent first.** Consent Mode v2 starts with everything denied. The Google script is not even added to the page until a visitor taps **Accept** in the notice (shown on the landing page, the
  join page and the phone results, never on the host screen or during a question). The choice is kept in the browser (`lamma_consent` in localStorage); **Cookie settings** in the landing footer and on
  the join page reopens it. **Decline** loads nothing.
- **No personal data.** Google Signals and ad personalization are off. A page is reported by its route pattern (`/host/:room`, `/play/:room`, `/join`), never with a room code, join code, token or
  query string, with the route name as its title and only the *origin* of the referrer. Events carry only counts, slugs and language codes: never a nickname, e-mail, user id, guest token or code.
- **Not on the admin.** `/admin` never loads it.
- **Events** (GA4 > Reports > Engagement > Events): `room_created` (difficulty, question_count, categories_count), `subcategory_selected` (parent_category, subcategory: slugs), `game_started`
  (player_count, question_count, difficulty, language), `player_joined` (language), `game_finished` (player_count, question_count, duration_seconds, rounded to 10 s), `play_again_clicked`,
  `language_switched` (to), `sign_up`, `login`. Each fires once.

**To do in the Google Analytics dashboard (Lamma cannot do these)**

1. Create the property and the web data stream (above); copy the Measurement ID.
2. Admin > Data collection and modification > **Data collection**: check that **Google signals data collection is OFF**, and leave **ads personalization** off.
3. Admin > Data collection and modification > **Data retention**: set **Event data retention** to 2 months or 14 months, as you prefer (the default is 2 months).
4. Admin > Data display > **Events**: mark the events you care about as **key events** (for example `game_started`, `game_finished`, `sign_up`).
5. Admin > Data display > **Custom definitions**: to see an event parameter in reports (for example `difficulty` or `subcategory`) register it as a custom dimension (event scope). `player_count`
   and `question_count` can be custom metrics.
6. Check it works on a real phone with **Admin > DebugView** or **Reports > Realtime** (see section 16 of `docs/manual-test-plan.md`).

If you ever add a Content-Security-Policy header, allow `https://www.googletagmanager.com` for scripts and `https://*.google-analytics.com` and `https://*.analytics.google.com` for connections.

**Upgrade notes from before Phase 8**

`deploy/deploy.sh` needs no change: `migrate --force` adds `question_seen` (what each player has already seen), a unique (room, question) index on `room_questions`, `categories.parent_id` and
`questions.seed_key` (filled for the existing questions), and `ContentSeeder` then adds the 13 subcategories and the new questions without touching what exists. Games already in the database are
safe: nothing is deleted or rewritten, the unique index refuses to be added (with a clear message, changing nothing) only if a room somehow has the same question twice, which the game never produces.

## 18. Player photos (selfies)

Phase 9. Besides a nickname a player can take a selfie with the phone's front camera. The host decides per room (On or Off when creating it, on by default). Photos belong to
**one game**: they are never saved to a profile, never shown in My games, and every new game (also "Play again") asks again.

- **Where they live:** the `player_photos` disk, `storage/app/public/player-photos/rooms/{room id}/{random id}.webp`, 256x256 WebP, about 5-15 KB each. They are served through the
  existing `public/storage` link at `https://<domain>/storage/player-photos/...` (nginx block with no listing, `noindex`, `Cache-Control: private`). File names are random and never
  contain a nickname; there is no page that lists them. `deploy.sh` already runs `storage:link`.
- **How long:** `LAMMA_PHOTO_RETENTION_HOURS` in `.env` (24 by default, see `.env.production.example`). `lamma:prune` runs **every hour** and deletes the photos of rooms that
  finished more than that long ago (a room the host closed too, so the results screen still shows them until then), of games nobody ended, and of lobbies nobody started (these at
  once, when they are closed after 6 hours). A player who leaves or is removed, a host who presses "Remove photo", and an account that is deleted take their photos away
  immediately. Folders of rooms that no longer exist are swept too. A picture taken on the join page and never used (the player left) sits in Livewire's temporary
  folder (`storage/app/private/livewire-tmp`, never served); `lamma:prune` deletes those after the same retention time.
- **Backups:** not included, on purpose (section 12). Keep them out of any file-level backup.
- **What the server needs:** `php8.3-gd` with JPEG and WebP, and `fileinfo` (section 2). `lamma:doctor` checks them. Uploads are small (the phone sends a JPEG of at most 640x640, a
  few hundred KB); the server refuses anything over 3 MB, anything that is not really a JPEG or WebP, and pictures over 12 megapixels. The nginx and PHP-FPM upload limits (8 MB)
  are already enough.
- **HTTPS is required for the camera.** Browsers only offer `getUserMedia` to https pages (and `localhost`). The live site is https. If you ever add a `Permissions-Policy`
  header, it must contain `camera=(self)`; `camera=()` would switch the selfie step off.
- **Privacy:** the join page tells the player "Your photo is shown on the host screen and deleted after the game". Photos are never written to the logs and never go to Google
  Analytics. The admin panel's room page has a **Players** tab with every player's thumbnail and a **Remove photo** action, for moderation only.
- **Upgrade from before Phase 9:** `deploy.sh` needs no change. `migrate --force` adds `avatar_path` and `avatar_updated_at` to `room_players`; rooms created earlier have photos on
  by default (nobody has a photo in them). `crontab`/`/etc/cron.d/lamma` needs no change either (the scheduler already runs every minute; `lamma:prune` became hourly in code).
