# Lamma · لمّة

A bilingual (Arabic / English) multiplayer quiz party game. One shared **host screen** (a laptop or TV) shows the questions; **players** join from their phones with a room code.
Laravel 13, Livewire 4, Alpine, Tailwind 4, Filament 5 (the admin panel) and Laravel Reverb. The live site is <https://lamma.build-syntax.com/>.

`CLAUDE.md` is the working guide (conventions, decisions, the game state machine, every phase); `docs/` has the design handoff, the manual test plan, the deployment guide and one report per phase.

## Run it locally

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate   # set REVERB_* and ADMIN_PASSWORD
php artisan migrate --seed
php artisan storage:link
composer dev        # server, queue worker, scheduler, Vite and Reverb in one terminal
```

The demo's two bots answer through the queue worker, so keep `composer dev` (or `php artisan queue:listen --tries=1 --timeout=0 --sleep=1`) running.

## Tests

```bash
composer test                               # Pint + PHPStan + Pest, on SQLite in memory (about 10-17 minutes)
vendor/bin/pest tests/Browser/FullGameTest.php   # real-browser tests: one file at a time (a whole-folder run can hang on Windows)
```

The tests run in the `testing` environment: `.env.testing` (committed) points at SQLite in memory and in-process drivers, so a test run, and `php artisan ... --env=testing`, can never reach your MySQL.

## Destructive commands refuse by default

`php artisan migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback` and `db:wipe` **refuse to run** in production, and on a development machine unless you set `LAMMA_ALLOW_DESTRUCTIVE=true`.
Your local MySQL holds the seeded content and test accounts; this stops a command (yours or an assistant's) from emptying it by accident.

To reset the local database on purpose:

```bash
php artisan lamma:backup-local                       # a dump in storage/app/backups first (mysqldump, password not on the command line)
LAMMA_ALLOW_DESTRUCTIVE=true php artisan migrate:fresh --seed    # bash; in PowerShell: $env:LAMMA_ALLOW_DESTRUCTIVE='true'; php artisan migrate:fresh --seed
```

or put `LAMMA_ALLOW_DESTRUCTIVE=true` in `.env` for as long as you need it, and set it back to `false` afterwards. It is never honoured in production. To try a migration without touching MySQL, use the testing database:
`php artisan migrate:fresh --env=testing` (SQLite in memory) or a Pest test that calls `migrate:rollback --path=...`.

## Deploying

`docs/deployment.md` takes a new Hetzner server to a live site; a push to `main` deploys after the tests pass (`.github/workflows`).
