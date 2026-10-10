# Lamma · لمّة — Claude Code prompt, Phase 7 (ready for deployment)

Paste the block below into Claude Code from the root of the project (`quiz-party`). It stands on its own; you don't need the shared preamble.

```
PHASE 7 — Finish everything pending and make Lamma ready to deploy

You are continuing work on Lamma · لمّة, a bilingual (Arabic/English) multiplayer quiz party game
(Laravel 13, Livewire 4, Alpine, Tailwind 4, Filament 5, Reverb). Phases 1-6 are done: the whole
game is playable and every screen in docs/design/HANDOFF.md is built in the Lamma design.

Goal of this phase: no screen left in the starter-kit style, enough content for real games, a
production configuration, a repeatable deployment to our Hetzner VPS (Ubuntu) through GitHub
Actions, and a full test pass that proves it.

Before writing code:
1. Read CLAUDE.md (conventions, decisions, the Lamma UI rules), docs/design/HANDOFF.md and
   docs/manual-test-plan.md.
2. Run `composer test` (Pint + PHPStan level 7 + Pest) and confirm it is green before you change
   anything. If it is not green, fix that first and tell me what was broken.

Decisions already made (do not re-open them): everything in the "Decisions" section of CLAUDE.md,
plus these for this phase:
- There is no dashboard. After login or registration, Fortify sends the user to their intended URL,
  otherwise to /me/games.
- The app is light-only. The Appearance settings page and dark mode are removed.
- Email verification stays off (Features::emailVerification() remains commented out).
- The brand files in public/brand/ and the sounds in public/sounds/ stay as they are. I will
  replace them with the official files later, using the same file names. Don't redesign them.
- The domain is not decided. Wherever a domain is needed, use the placeholder `lamma.example` and
  list every place it appears in your final report. Never invent credentials or secrets.

Rules for every section:
- Follow CLAUDE.md: tokens only (no raw hex), logical utilities only (ms-/me-/ps-/pe-/start-/end-),
  every user-facing string through __() with both ar and en entries, numbers and names isolated
  with App\Support\Isolate, room codes / emails / passwords / codes always dir="ltr".
- New screens use <x-layouts::lamma> and the <x-lamma.*> components. If a component you need does
  not exist, add it to resources/views/components/lamma/ AND to the /_components gallery (a test
  fails otherwise).
- Write Pest tests with the code. Run `vendor/bin/pint` and `composer types:check` before each commit.
- Commit after each numbered section with a clear message.
- If something is not specified, check HANDOFF.md and the reference screens first, then pick the
  simplest option that matches the existing screens and note it in the report. Only stop and ask me
  for something that cannot be undone.

1. Account area in the Lamma design
   a. Remove the dashboard: the `dashboard` route and view, tests/Feature/DashboardTest.php,
      layouts/app.blade.php, layouts/app/sidebar.blade.php, components/placeholder-pattern,
      desktop-user-menu, app-logo / app-logo-icon (if unused), resources/views/flux/*. Set
      `fortify.home` to /me/games. Update every link that pointed at the dashboard (landing header,
      mobile menu, footer) to "My games".
   b. A user menu for logged-in pages: <x-lamma.user-menu> (avatar initial + name, a dropdown with
      My games, Host a game, Settings, Log out; keyboard accessible, Escape closes, aria-expanded).
      Use it in the landing header, /me/games and the settings pages so they share one header.
   c. Settings rebuilt in <x-layouts::lamma>, with a settings shell: page title, <x-lamma.segmented>
      link tabs (Profile · Security), the form in a white sticker card, max width ~640px, phone
      first and centred on desktop.
      - Profile: name, email, preferred language (ar/en, saved to users.preferred_locale and
        applied to the session), Save. Below it a "Delete account" danger zone: an outline button
        that opens a confirm dialog (native <dialog> or Alpine, focus trapped, Escape closes) asking
        for the password. Deleting the account must keep finished games intact for the other
        players (check the foreign keys; null the user_id on room_players rather than cascading,
        and finish any lobby the user hosts through RoomManager::close).
      - Security (behind password.confirm, as now): change password; two-factor authentication
        (enable, the QR code and setup key, confirm with a 6-digit code, show / regenerate recovery
        codes, disable); passkeys (list, add, remove). Keep every Fortify behaviour and the
        existing tests; only the markup and layout change.
      - Remove the Appearance page and its route, and any dark-mode script or `dark:` classes left
        in the views.
   d. The two-factor challenge page: replace flux:otp with a Lamma field (inputmode="numeric",
      autocomplete="one-time-code", dir="ltr", 6 digits, paste works) plus the "use a recovery
      code" switch.
   e. When no view uses Flux any more, remove livewire/flux from composer.json, its CSS/JS
      imports and the @fluxAppearance / @fluxScripts directives. Add a test that fails if
      `<flux:` appears anywhere in resources/views. If something truly still needs Flux, keep it
      and explain why in the report.
   Tests: settings pages render in en and ar (dir attribute, no missing keys), profile update,
   language change, password change, 2FA enable/confirm/disable, passkey routes still work,
   delete account keeps other players' results, login and register land on /me/games (or the
   intended URL, e.g. the "Save your score" flow still returns to the results page).

2. Error and maintenance pages
   - resources/views/errors/: 403, 404, 419 (page expired: "Refresh and try again"), 429 (too many
     attempts), 500 and 503 (maintenance), in the Lamma style (cream page, logo, a big rounded
     number tile with a sticker shadow, one line of copy, a "Back to home" button, the language
     switcher), in both languages.
   - They must render even when the database or the session is down: a minimal layout with no DB
     queries, no Livewire, no Echo. The locale comes from the session if available, otherwise
     Accept-Language, otherwise ar.
   - A room that no longer exists (/host/XXXX, /play/XXXX) shows the 404 page with a "Join a
     game" button.
   Tests: each page renders in en and ar, 404 for an unknown room code, 419 on an expired form,
   and the 500 page renders with the database connection broken.

3. Emails
   - Publish the Laravel mail components and theme them for Lamma (logo, cream background, coral
     button with navy text, IBM Plex Sans Arabic with a system fallback, rtl when Arabic).
   - User implements HasLocalePreference (preferred_locale), so the password reset email goes out
     in the user's language. Translate the reset notification (subject, lines, button, footer) into
     both languages.
   - Mail is queued. .env.example keeps MAIL_MAILER=log locally; the production example uses smtp
     with empty placeholders.
   Tests: the reset email is sent in Arabic for an ar user and in English for an en user, with
   dir="rtl" in the Arabic one.

4. Question content
   - Grow the seeded content to at least 200 questions in at least 8 categories (keep general
     knowledge, science and geography; add e.g. history, sports, food, arts & music,
     technology, animals & nature, the Arab world). Roughly 40% easy, 40% medium, 20% hard, and
     at least 10 questions for every category x difficulty pair that a host can choose, so a
     10-question game in one category on one difficulty does not run out.
   - Quality rules: natural Modern Standard Arabic (not a word-for-word translation) and natural
     English; one clearly correct answer and three plausible wrong ones; no answers that will go
     out of date (current office holders, "latest", records likely to change); no politically or
     religiously divisive questions; facts you are sure of. Where you are not sure of a fact,
     leave the question out.
   - Move the questions to a data file (database/seeders/data/questions.php or JSON) so the seeder
     stays small. Split the seeders: ContentSeeder (categories + questions, idempotent, safe to
     run in production) and DevSeeder (the test@example.com / english@example.com users, local
     only). DatabaseSeeder runs both locally and only ContentSeeder in production. AdminSeeder
     refuses to run in production without ADMIN_EMAIL and a strong ADMIN_PASSWORD.
   - Add the matching category slugs to App\Support\CategoryStyle (icon + tint) for every new
     category.
   Tests: every seeded question has exactly 4 options and exactly one correct, text in both
   languages for the question and every option, no duplicate question text in either language,
   every category x difficulty has >= 10 questions, the seeder is idempotent (running it twice
   adds nothing), and the seeded test users are not created in production.

5. Production configuration and hardening
   - .env.production.example: APP_ENV=production, APP_DEBUG=false, APP_URL=https://lamma.example,
     LOG_CHANNEL=daily, LOG_LEVEL=warning, Redis for CACHE_STORE / QUEUE_CONNECTION /
     SESSION_DRIVER, SESSION_SECURE_COOKIE=true, MySQL, smtp mail placeholders, and Reverb served
     over wss through nginx: REVERB_SERVER_HOST=127.0.0.1, REVERB_SERVER_PORT=8080,
     REVERB_HOST=lamma.example, REVERB_PORT=443, REVERB_SCHEME=https, VITE_REVERB_* matching.
     Check that the "loopback VITE_REVERB_HOST becomes the page host" trick in the front end does
     not break this setup.
   - Trust the proxy headers (nginx on the same box) and force https URLs in production.
   - Make sure that in production: /_components is not registered, the seeded test users do not
     exist, Admin::canAccessPanel() only allows active admins, Telescope/debug tools (if any) are
     off, and the join / login rate limits are on.
   - Every route must survive `php artisan optimize` (config, route, view and event cache),
     including the closure route `play.save` (move it to a small controller if route:cache has a
     problem with it).
   - A `php artisan lamma:doctor` command that checks the production setup and prints a table of
     pass / warn / fail: APP_KEY set, debug off, https APP_URL, database and Redis reachable, the
     queue connection is not sync, Reverb config present and its port reachable, the scheduler
     has run in the last 26 hours (have lamma:prune write a heartbeat to the cache), mail is not
     `log`, the storage link exists, the build manifest exists, enough questions per category and
     difficulty, and a warning (not a failure) while the placeholder brand and sound files are
     still in place (compare file hashes with the current placeholders). Exit code 1 on any fail.
   Tests: lamma:doctor (fail on debug on, pass on a good config, warn on placeholders), the
   production route list has no _components, https forcing.

6. Deployment kit (Hetzner VPS, Ubuntu 24.04, one server)
   Put these files in deploy/ and explain them in docs/deployment.md, step by step for a fresh
   server:
   - What to install: PHP 8.3-FPM with the needed extensions (check composer.json), Composer,
     Node 22, MySQL 8, Redis, nginx, Supervisor, certbot.
   - deploy/nginx/lamma.conf: the site on 443 with http->https redirect, PHP-FPM, long cache headers
     for /build and /brand, gzip, client_max_body_size for question images, and the Reverb
     WebSocket proxy (/app and /apps to 127.0.0.1:8080 with the Upgrade/Connection headers and long
     read timeouts).
   - deploy/supervisor/lamma-worker.conf (queue:work redis --sleep=1 --tries=3 --max-time=3600) and
     deploy/supervisor/lamma-reverb.conf (reverb:start --host=127.0.0.1 --port=8080).
   - The scheduler cron line (* * * * * php artisan schedule:run).
   - deploy/deploy.sh, safe to run again: php artisan down --render="errors::503" --retry=15,
     git pull --ff-only, composer install --no-dev --optimize-autoloader, npm ci && npm run build,
     php artisan migrate --force, db:seed --class=ContentSeeder --force, storage:link (if
     missing), optimize, queue:restart, reverb:restart, up, then lamma:doctor. Stop on the first
     error and bring the site back up.
   - .github/workflows/deploy.yml: runs on push to main after the existing tests workflow succeeds
     (workflow_run, or one workflow with a needs: chain), then SSHes into the server with
     appleboy/ssh-action and runs deploy/deploy.sh. Secrets: SSH_HOST, SSH_USER, SSH_PRIVATE_KEY,
     SSH_PORT, APP_PATH. Pin actions to commit SHAs like tests.yml does.
   - docs/deployment.md also covers: the first deploy, creating the admin, backups (a nightly
     mysqldump cron with 7-day rotation), how to roll back (git checkout the previous commit and run
     deploy.sh), where the logs are, and how to test with phones on the live domain.

7. Full verification (do all of it, and paste the results in the report)
   a. `composer test` green: Pint, PHPStan level 7 with no new baseline entries, the whole Pest
      suite.
   b. Browser smoke tests with Pest 4's browser plugin (pestphp/pest-plugin-browser, Playwright is
      already installed on this machine if `npx playwright --version` works; otherwise install it
      for the dev environment only). In tests/Browser:
      - landing, join, login, register, forgot password, 404, /me/games and both settings tabs,
        each in en and ar, at 390x844 and 1440x900: no JavaScript errors, no console errors,
        assertNoAccessibilityIssues(), html dir is right, and no horizontal scroll on the phone size.
      - the host screens (create room, lobby, a question, the reveal, the podium) at 1280x720 and
        1920x1080 with seeded game states: the page does not scroll.
      - one full game in the browser: a host page and two player pages (one ar guest, one en
        logged-in user) against a running Reverb on a test port with QUEUE_CONNECTION=sync. If
        Reverb cannot run inside the test run, drive the same game through GameEngine and check
        each screen by reloading it, and say so in the report.
      Add a separate CI job for the browser tests in tests.yml (with the Playwright install step)
      so they don't slow down the main job.
   c. A production build check: `npm run build` succeeds, then with APP_ENV=production and
      APP_DEBUG=false run `php artisan optimize` and `php artisan route:list` without errors, and
      `php artisan lamma:doctor` (it may warn about placeholders and fail on things that only exist
      on the server; list which).
   d. `php artisan migrate:fresh --seed` from zero, then `php artisan lamma:prune`, then
      `php artisan migrate:rollback` all the way and migrate again: no errors.
   e. Go through docs/manual-test-plan.md and tick in a new column everything the automated tests
      now cover. Leave the rest (real phones, screen readers, LAN) for me, and update the plan for
      the new account pages, error pages and the live-domain checks.

8. Docs
   - Update CLAUDE.md: Status "Phases 1-7 done", the account area, error pages, emails, the
     seeders split, production config, lamma:doctor, the deploy kit, the browser tests and how
     to run them. Remove anything that is no longer true (dashboard, Flux, Appearance).
   - Remove the Flux line from the stack table if Flux is gone.

When the phase is done, report:
1. What you built, per section, and any deviation from this prompt and why.
2. The test results: number of tests and assertions, PHPStan, Pint, the browser tests, the
   production build check and lamma:doctor output.
3. Everything that needs me before going live, as a checklist: the domain (every place
   lamma.example appears), DNS, the server, SMTP credentials, the GitHub secrets, ADMIN_EMAIL /
   ADMIN_PASSWORD, the official brand files, the sound files, and the manual phone test.
Then stop.
```
