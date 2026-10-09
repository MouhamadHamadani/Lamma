#!/usr/bin/env bash
#
# Nightly MySQL backup for Lamma, run by cron (deploy/cron/lamma) as the app's Unix user. Keeps the last 7 days.
#   bash /var/www/lamma/deploy/backup.sh
# Restore (into an EMPTY database):  gunzip -c /var/backups/lamma/lamma-2026-01-31-0230.sql.gz | mysql -u lamma -p lamma
#
# The database name and credentials come from the app's .env. The password is handed to mysqldump through a temporary file, never on the
# command line (where other users could read it with ps).

set -Eeuo pipefail

main() {
    local app_dir="${APP_PATH:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
    local backup_dir="${BACKUP_DIR:-/var/backups/lamma}"
    local keep_days="${KEEP_DAYS:-7}"

    umask 0077
    mkdir -p "$backup_dir"

    env_value() {
        grep -E "^$1=" "$app_dir/.env" | head -n 1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'\$//"
    }

    local db_host db_port db_name db_user db_pass
    db_host="$(env_value DB_HOST)"; db_port="$(env_value DB_PORT)"; db_name="$(env_value DB_DATABASE)"
    db_user="$(env_value DB_USERNAME)"; db_pass="$(env_value DB_PASSWORD)"

    defaults="$(mktemp)"                 # global on purpose: the EXIT trap below runs after main() has returned
    trap 'rm -f "$defaults"' EXIT
    {
        echo "[client]"
        echo "host=${db_host:-127.0.0.1}"
        echo "port=${db_port:-3306}"
        echo "user=${db_user}"
        echo "password=${db_pass}"
    } > "$defaults"

    local file="$backup_dir/lamma-$(date +%F-%H%M).sql.gz"

    mysqldump --defaults-extra-file="$defaults" --single-transaction --quick --no-tablespaces --routines "$db_name" | gzip -9 > "$file"

    # A dump that is almost empty means something went wrong: fail loudly instead of keeping a useless "backup".
    if [ "$(stat -c %s "$file")" -lt 1024 ]; then
        echo "Backup $file is suspiciously small." >&2
        rm -f "$file"
        exit 1
    fi

    find "$backup_dir" -name 'lamma-*.sql.gz' -type f -mtime +"$keep_days" -delete

    echo "$(date '+%F %T') backup ok: $file ($(du -h "$file" | cut -f1)); keeping $keep_days days"
}

main "$@"
