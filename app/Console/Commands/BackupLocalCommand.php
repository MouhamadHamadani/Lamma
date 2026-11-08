<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * One command before any local reset: a mysqldump of the development database into storage/app/backups (never in the repository: storage/app is ignored by git). The password goes to
 * mysqldump in the MYSQL_PWD environment variable, not on the command line. It is for a development machine; the server's own backup is deploy/backup.sh.
 */
#[Signature('lamma:backup-local {--path= : Where to write the dump (default: storage/app/backups/local-<database>-<date>.sql)} {--connection= : The database connection to dump (default: the default one)}')]
#[Description('Dump the local MySQL database to a .sql file, so a reset (migrate:fresh) can be undone')]
class BackupLocalCommand extends Command
{
    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->components->error('This is for a development machine. On the server the nightly deploy/backup.sh does it.');

            return self::FAILURE;
        }

        $connection = (string) ($this->option('connection') ?: config('database.default'));
        $config = (array) config("database.connections.{$connection}");
        if (! in_array($config['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            $this->components->error("The default database connection is \"{$connection}\" ({$config['driver']}): only MySQL / MariaDB can be dumped here. A SQLite file can simply be copied.");

            return self::FAILURE;
        }

        $database = (string) ($config['database'] ?? '');
        $path = (string) ($this->option('path') ?: storage_path('app/backups/local-'.$database.'-'.now()->format('Ymd-His').'.sql'));
        @mkdir(dirname($path), 0775, true);

        $command = [
            self::binary(), '--single-transaction', '--routines', '--no-tablespaces',
            '--host='.($config['host'] ?? '127.0.0.1'), '--port='.($config['port'] ?? 3306), '--user='.($config['username'] ?? 'root'),
            '--default-character-set=utf8mb4', '--result-file='.$path, $database,
        ];

        $result = Process::env(['MYSQL_PWD' => (string) ($config['password'] ?? '')])->timeout(300)->run($command);

        if ($result->failed()) {
            $this->components->error('mysqldump failed: '.trim($result->errorOutput() ?: $result->output()));
            $this->line('Run it yourself, with the right path: '.self::exampleLine($database, $path));

            return self::FAILURE;
        }

        $this->components->info("Dumped \"{$database}\" to {$path} (".number_format((int) @filesize($path) / 1024, 0).' KB). Restore it with: mysql -u root '.$database.' < "'.$path.'"');

        return self::SUCCESS;
    }

    /** MYSQLDUMP_PATH if set, else the one in a WAMP install next to this PHP (C:\wamp64\bin\mysql\...), else `mysqldump` on the PATH. */
    public static function binary(): string
    {
        if (is_string($path = config('lamma.mysqldump_path')) && $path !== '') {
            return $path;
        }

        $wamp = glob(dirname(PHP_BINARY, 3).'/mysql/mysql*/bin/mysqldump.exe') ?: [];

        return $wamp[0] ?? 'mysqldump';
    }

    /** The line to run by hand when the command cannot find or run mysqldump (the password is asked for). */
    public static function exampleLine(string $database, string $path): string
    {
        return 'mysqldump -u root -p --single-transaction --routines '.$database.' > "'.$path.'"';
    }
}
