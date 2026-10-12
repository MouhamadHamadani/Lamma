INSTALL — Put Lamma · لمّة on this Google Cloud VM, fully configured and ready to play

Fill in:
- DEPLOY_FILE   = ~/deploy.sh>
- REPO_URL      = git@github.com:MouhamadHamadani/Lamma.git
- BRANCH        = main
- DOMAIN        = lamma.build-syntax.com
- ADMIN_EMAIL   = mouhamad.hamadani@gmail.com

Context:
Lamma is a bilingual (Arabic/English) multiplayer quiz party game: Laravel 13, Livewire 4, Alpine,
Tailwind 4, Filament 5, Laravel Reverb (WebSockets), Redis (queue, cache, sessions), MySQL 8.
A host screen runs the game and phones answer in real time over Reverb. The server owns the timer:
a delayed RevealQuestion job on the queue ends each question, so the queue worker, Reverb and the
scheduler must all run, or games freeze.
The repo already has a deployment kit written for a Hetzner VPS (Phase 7): deploy/deploy.sh,
deploy/nginx/lamma.conf, deploy/supervisor/lamma-worker.conf, deploy/supervisor/lamma-reverb.conf,
.env.production.example, docs/deployment.md, the lamma:doctor command, ContentSeeder and
AdminSeeder. You are running on the server itself, a Google Compute Engine VM with Ubuntu.
Goal: a working install at https://<host> that I can test with a laptop as the host screen and
several phones over mobile data, that survives a reboot, and that later deploys work on.

Ground rules:
- Read before you act: DEPLOY_FILE, then (once the repo is cloned) CLAUDE.md, docs/deployment.md,
  everything in deploy/, .env.production.example and composer.json. The deploy file and the kit are
  the plan; follow them and only change what GCP or this server needs. Explain each change.
- Keep server-specific values (host name, passwords, keys) in .env and /etc, never in the repo. If
  a repo file must change (for example a hard-coded Hetzner path or user), make the smallest change
  on a branch named deploy/gcp, commit it, and tell me. Do not push to main.
- Back up any existing system config file before you edit it (copy to <file>.bak-<date>).
- Never print passwords, APP_KEY, Reverb secrets or private keys to the terminal or into your
  report. Generate secrets with `openssl rand`, write them straight to .env (mode 640) and to
  /root/lamma-secrets.txt (mode 600), and tell me where they are.
- Each step must be safe to run again: check before you create (users, databases, keys, files).
- Ask me and wait only when you need something from me (adding a GitHub deploy key, a GCP firewall
  or IP change you cannot make yourself, a missing value). Otherwise keep going.
- Keep a running log of what you did in /root/lamma-install-log.md.

1. Look around first (report this before installing anything)
   - Ubuntu version, vCPUs, RAM, disk free, swap, current user, sudo, timezone.
   - GCP facts from the metadata server (header "Metadata-Flavor: Google"): project id, zone,
     instance name, machine type, network tags, external IP, and whether OS Login is enabled
     (instance or project attribute enable-oslogin).
   - Whether the external IP is static or ephemeral (try `gcloud compute addresses list` if gcloud
     works with this VM's service account; otherwise say you could not tell). If it is ephemeral,
     warn me: a stop/start changes it and breaks the sslip.io host name and the certificate. Give
     me the exact gcloud command to promote it to a static address.
   - What is already installed or running (nginx, apache, php, mysql, redis, supervisor, node),
     anything already listening on 80, 443, 3306, 6379 or 8080, and whether ufw is active.
   - What DEPLOY_FILE does, step by step, and which paths, user and services it assumes.
   - The host name you will use: DOMAIN, or <ip-with-dashes>.sslip.io. Check that it resolves to
     this VM's external IP with `dig +short`. If a real DOMAIN does not resolve here yet, stop and
     tell me which DNS A record to add.

2. GCP firewall and OS firewall
   - Ports 80 and 443 must be reachable from the internet. Check the VM has the http-server and
     https-server network tags (or a VPC rule that allows tcp:80,443). If gcloud can add them, do
     it; otherwise give me the exact console steps or gcloud command and wait until I confirm.
   - Port 8080 (Reverb) and 3306 / 6379 must NOT be open to the internet; Reverb is only reached
     through nginx on 443.
   - If you enable ufw: allow OpenSSH (22) FIRST, then 80 and 443, then enable it. Confirm port 22 is
     still allowed before you finish. Do not lock me out.
   - GCP blocks outbound port 25. Do not use it for mail (see section 6).

3. System packages (Ubuntu 24.04, adapt if the version differs)
   - Add a swap file if RAM is under 4 GB (2 GB swap, swappiness 10), so Composer and the Vite build
     do not run out of memory.
   - Set the timezone to UTC (the app stores UTC; display is handled in the app).
   - Install: PHP 8.3 FPM and CLI with every extension composer.json and Laravel need (at least
     mbstring, xml, curl, zip, intl, bcmath, gd, mysql, redis, opcache, pcntl; check `composer
     check-platform-reqs` after cloning), Composer, Node 22 (NodeSource), MySQL 8, Redis, nginx,
     Supervisor, certbot with the nginx plugin, git, unzip, acl. Also unattended-upgrades.
   - Make sure every service is enabled to start on boot.

4. App user, code and access to GitHub
   - Use the app path, owner and group that DEPLOY_FILE and docs/deployment.md expect (default to
     /var/www/lamma, owned by a `lamma` user in the www-data group if they do not say).
   - If the repo is private: create an ed25519 deploy key for the app user, show me ONLY the public
     key, and wait until I add it to the GitHub repo as a read-only deploy key. Then add github.com
     to known_hosts and test with `ssh -T git@github.com`.
   - Clone REPO_URL (BRANCH) into the app path, if it is not already there.
   - Permissions: storage/ and bootstrap/cache/ writable by the app user and www-data (setfacl),
     the rest read-only for www-data.

5. MySQL and Redis
   - MySQL: bind to 127.0.0.1, create database `lamma` (utf8mb4, utf8mb4_unicode_ci) and a user
     `lamma`@`localhost` with a generated password and rights on that database only. Run the
     equivalent of mysql_secure_installation (no anonymous users, no remote root, no test db).
   - Redis: bind 127.0.0.1 and ::1 only, protected-mode on, `supervised systemd`, a maxmemory that
     fits the VM (about 10% of RAM) with `noeviction` (queued jobs must never be evicted).

6. .env
   - Start from .env.production.example and replace every lamma.example with the host name.
   - Generate APP_KEY (php artisan key:generate), REVERB_APP_ID, REVERB_APP_KEY, REVERB_APP_SECRET,
     DB_PASSWORD. Keep the production values from the example: APP_ENV=production, APP_DEBUG=false,
     https APP_URL, Redis for cache, queue and sessions, SESSION_SECURE_COOKIE=true,
     REVERB_SERVER_HOST=127.0.0.1, REVERB_SERVER_PORT=8080, REVERB_HOST=<host>, REVERB_PORT=443,
     REVERB_SCHEME=https, and VITE_REVERB_* matching the public values.
   - Mail: SMTP credentials are not decided. Use MAIL_MAILER=log for now and leave the SMTP
     placeholders (port 587, never 25) commented underneath. Tell me lamma:doctor will warn or fail
     on this until I add SMTP.
   - ADMIN_EMAIL from the Fill in block and a generated strong ADMIN_PASSWORD (saved to
     /root/lamma-secrets.txt), for AdminSeeder.
   - The VITE_* values are baked into the JavaScript at build time, so the .env must be complete
     BEFORE the first `npm run build`.

7. PHP-FPM tuning
   - opcache on, memory_consumption 192, validate_timestamps=0 (so check that DEPLOY_FILE reloads
     php8.3-fpm after a deploy; if it does not, add a reload step and tell me).
   - pm = dynamic with pm.max_children sized to the RAM (about RAM-in-MB minus 1 GB for MySQL and
     Redis, divided by 60), and pm.max_requests 500.
   - upload_max_filesize and post_max_size match nginx client_max_body_size (question images).
   - memory_limit 256M for FPM.

8. nginx and HTTPS
   - Start with a plain HTTP server block for the host name (with /.well-known/acme-challenge),
     get the certificate with certbot, then install deploy/nginx/lamma.conf with the host name,
     certificate paths, app path and PHP-FPM socket filled in. Remove the default site.
   - Keep everything the kit already has: http→https redirect, long cache headers on /build and
     /brand, gzip, client_max_body_size, and the Reverb proxy (/app and /apps to 127.0.0.1:8080
     with Upgrade/Connection headers, proxy_http_version 1.1 and long read timeouts).
   - Add security headers if the kit lacks them (X-Content-Type-Options, Referrer-Policy,
     X-Frame-Options SAMEORIGIN) and hide the nginx version.
   - `nginx -t` must pass. Confirm certbot's renewal timer is active and run `certbot renew
     --dry-run`.
   - If you use sslip.io, note in the report that Let's Encrypt has weekly per-domain limits on
     sslip.io, so do not request certificates over and over.

9. Supervisor, scheduler and Reverb limits
   - Install deploy/supervisor/lamma-worker.conf and lamma-reverb.conf with the app path and user
     filled in. Worker: queue:work redis --sleep=1 --tries=3 --max-time=3600, numprocs 2,
     stopwaitsecs 3600. Reverb: reverb:start --host=127.0.0.1 --port=8080, autorestart.
   - Raise the open-files limit for Reverb (minfds in supervisord.conf, e.g. 10000) so many phones
     can connect.
   - The scheduler cron for the app user: * * * * * cd <app path> && php artisan schedule:run
     >> /dev/null 2>&1
   - The nightly mysqldump backup with 7-day rotation from docs/deployment.md.

10. First deploy with DEPLOY_FILE
   - Run DEPLOY_FILE as the user it expects. If it fails, fix the cause (not the symptom), note it
     in the log and run it again. It should do down → pull → composer --no-dev → npm ci && build →
     migrate --force → ContentSeeder → storage:link → optimize → queue:restart → reverb:restart →
     up → lamma:doctor.
   - Then run AdminSeeder once with the ADMIN_EMAIL / ADMIN_PASSWORD from .env, and confirm it
     refuses a weak password (do not leave a weak one behind).
   - Confirm the seeded test users do not exist in production.

11. Prove it works (run every check and paste the results in the report)
   a. `php artisan lamma:doctor`: every check passes except the expected ones (placeholder brand and
      sound files, mail = log). List any other warn or fail and fix it.
   b. HTTP from the server, against the public host name:
      - https://<host>/ returns 200, http:// redirects to https://, /up returns 200
      - /admin redirects to the admin login, /_components returns 404
      - /build/manifest assets load with long cache headers
      - an unknown room /play/ZZZZ shows the Lamma 404 page
   c. WebSocket through nginx: a handshake to wss://<host>/app/<REVERB_APP_KEY>?protocol=7 returns
      101 Switching Protocols and a pusher:connection_established message (use a short Node
      script with the ws package in /tmp, not in the repo).
   d. A real game on the real services: with php artisan tinker (or a throwaway script in /tmp),
      create a host, a room with 3 questions at 10 seconds, two guest players, mark them ready,
      call GameEngine::start, and check without answering that the queue worker reveals and
      advances each question on its own and the room ends in `finished`. Watch the worker log while
      it runs. Then delete everything this test created.
   e. Services: systemctl is-enabled and is-active for nginx, php8.3-fpm, mysql, redis-server,
      supervisor, cron; supervisorctl status shows the workers and Reverb RUNNING. Do not reboot
      the VM yourself (you are running on it); tell me to reboot and give me the three commands to
      check everything came back.
   f. Logs are clean: storage/logs, nginx error log, Reverb and worker logs show no errors from
      the checks above.

12. Hand over
   Write docs/deployment-gcp.md on the deploy/gcp branch (what differs from the Hetzner guide: the
   GCP firewall tags, static IP, OS Login, port 25, sslip.io, swap) and commit it. Then report:
   1. The URL, the admin login URL and ADMIN_EMAIL (the password is in /root/lamma-secrets.txt).
   2. Everything you installed and configured, every change to the deploy file or the kit and why,
      and any repo commits on deploy/gcp.
   3. All check results from section 11.
   4. GitHub Actions deploys: the values for SSH_HOST, SSH_USER, SSH_PORT and APP_PATH. For
      SSH_PRIVATE_KEY, create a separate key pair for CI, add its public key to the deploy user's
      authorized_keys, test it with `ssh -i <key> <user>@localhost`, and tell me the command to
      print the private key myself (do not print it). If OS Login is enabled and blocks
      authorized_keys, say so and give me the options.
   5. A checklist of what still needs me: static IP, the real domain (and every place to change
      it: .env APP_URL, REVERB_HOST, VITE_REVERB_HOST, then rebuild, then certbot for the new
      name), SMTP credentials, the official brand and sound files, the GitHub secrets, the reboot
      check, and the phone test below.
   6. A short phone test for me: open https://<host> on the laptop, log in, create a room; join
      from two phones on mobile data (not Wi-Fi), one in Arabic as a guest and one in English;
      play a full 10-question game; refresh one phone mid-question and check it comes back to the
      same question with the right time left.
Then stop.