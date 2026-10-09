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

Fill in: `APP_URL` and `REVERB_HOST` (your domain), `DB_PASSWORD`, the `MAIL_*` values, and `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`
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

The cron file has two lines: the Laravel scheduler every minute (`php artisan schedule:run`, which runs `lamma:prune` daily and records the heartbeat that
`lamma:doctor` checks) and the nightly backup (section 12).

## 8. The first deploy

```bash
# as lamma, in /var/www/lamma
php artisan key:generate          # writes APP_KEY into .env. Only on the very first deploy.
bash deploy/deploy.sh
```

`deploy/deploy.sh` is the same script every later deploy uses. In order: maintenance page on (`php artisan down --render="errors::503" --retry=15`),
`git pull --ff-only`, `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`, `php artisan migrate --force`,
`php artisan db:seed --class=ContentSeeder --force` (adds the 400 shipped questions; it only ever adds what is missing), `storage:link` if missing,
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
| Disk full | `df -h`; old logs in `storage/logs`; old dumps in `/var/backups/lamma` |

After changing `.env`, run `php artisan optimize` (config is cached) and `php artisan queue:restart && php artisan reverb:restart`.
