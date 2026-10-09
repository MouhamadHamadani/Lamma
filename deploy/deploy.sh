#!/usr/bin/env bash
#
# Deploy Lamma on the server. Safe to run again at any time. Run it as the app's Unix user ("lamma"), by hand or from GitHub Actions:
#
#   bash /var/www/lamma/deploy/deploy.sh               # the normal deploy: pull main, build, migrate, restart
#   bash /var/www/lamma/deploy/deploy.sh --no-pull     # redeploy what is checked out (used to roll back, see docs/deployment.md)
#
# What it does, in order: maintenance mode on, git pull --ff-only, composer install, npm ci + build, migrate, content seeder, storage link,
# optimize, restart the queue workers and Reverb, maintenance mode off, then `php artisan lamma:doctor`.
# It stops at the first error and brings the site back up (so a failed deploy never leaves visitors on the maintenance page).
#
# Everything is inside main(), which bash reads completely before it runs anything: `git pull` may replace this very file.

set -Eeuo pipefail

main() {
    local pull=1
    for arg in "$@"; do
        case "$arg" in
            --no-pull) pull=0 ;;
            *) echo "Unknown option: $arg (only --no-pull exists)" >&2; exit 2 ;;
        esac
    done

    # The app folder: APP_PATH if set (GitHub Actions passes it), else the folder this script lives in.
    app_dir="${APP_PATH:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
    cd "$app_dir"

    # These stay global (no `local`): the EXIT trap below runs after main() has returned.
    php="${PHP_BIN:-php}"
    composer="${COMPOSER_BIN:-composer}"
    down=0

    # Files created here must stay writable by the group (the web app and the workers run as the same user anyway).
    umask 0002

    say() { printf '\n==> %s\n' "$*"; }

    finish() {
        local code=$?
        if [ "$down" -eq 1 ]; then
            echo "==> Bringing the site back up"
            "$php" artisan up || true
        fi
        if [ "$code" -ne 0 ]; then
            echo "==> Deploy FAILED (exit code $code). The site is up on whatever state the failed step left; see the message above." >&2
        fi
        exit "$code"
    }
    trap finish EXIT

    [ -f .env ] || { echo "No .env in $app_dir: copy .env.production.example to .env and fill it in first (docs/deployment.md)." >&2; exit 1; }

    # Maintenance mode needs the app installed; on the very first deploy there is nothing to take down yet.
    if [ -d vendor ]; then
        say "Maintenance mode on"
        "$php" artisan down --render="errors::503" --retry=15
        down=1
    fi

    if [ "$pull" -eq 1 ]; then
        say "Pulling the latest code (fast-forward only)"
        git pull --ff-only
    else
        say "Skipping git pull (--no-pull): deploying $(git rev-parse --short HEAD)"
    fi

    say "Installing PHP dependencies"
    "$composer" install --no-dev --optimize-autoloader --no-interaction --prefer-dist

    say "Building the front end"
    npm ci --no-audit --no-fund
    npm run build

    say "Migrating the database"
    "$php" artisan migrate --force

    say "Adding any new categories and questions (never changes existing ones)"
    "$php" artisan db:seed --class=ContentSeeder --force

    if [ ! -e public/storage ]; then
        say "Linking public storage"
        "$php" artisan storage:link
    fi

    say "Caching config, routes, views and events"
    "$php" artisan optimize

    say "Restarting queue workers and Reverb (Supervisor starts them again)"
    "$php" artisan queue:restart
    "$php" artisan reverb:restart

    say "Maintenance mode off"
    "$php" artisan up
    down=0

    say "Checking the setup"
    "$php" artisan lamma:doctor

    say "Deployed $(git rev-parse --short HEAD)"
}

main "$@"
exit
