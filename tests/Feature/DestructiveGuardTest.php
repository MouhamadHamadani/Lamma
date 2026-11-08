<?php

use App\Console\Commands\BackupLocalCommand;
use App\Providers\AppServiceProvider;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

/*
 * Phase 14.1: an agent once ran `migrate:fresh --env=testing`; there was no .env.testing, so it ran against the local MySQL and emptied it. Now there is a .env.testing that can
 * only reach SQLite in memory, destructive commands refuse on a development machine unless LAMMA_ALLOW_DESTRUCTIVE=true (and always in production), and a local dump is one command.
 */

/** Is `migrate:fresh` (and so its four siblings) switched off right now? */
function destructiveCommandsProhibited(): bool
{
    return (new ReflectionProperty(FreshCommand::class, 'prohibitedFromRunning'))->getValue();
}

/** Apply the provider's rule as it would be at boot in this environment. */
function bootDestructiveRule(string $environment, bool $allowed): void
{
    app()['env'] = $environment;
    config(['lamma.allow_destructive' => $allowed]);
    app()->getProvider(AppServiceProvider::class)->configureDestructiveCommands();
}

afterEach(fn () => DB::prohibitDestructiveCommands(false));

describe('.env.testing', function () {
    it('exists and can only reach SQLite in memory, with in-process drivers', function () {
        $env = collect(file(base_path('.env.testing'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
            ->reject(fn (string $line) => str_starts_with($line, '#'))
            ->mapWithKeys(function (string $line) {
                [$key, $value] = explode('=', $line, 2);

                return [$key => trim($value, '"')];
            });

        expect($env['APP_ENV'])->toBe('testing')->and($env['DB_CONNECTION'])->toBe('sqlite')->and($env['DB_DATABASE'])->toBe(':memory:')
            ->and($env['CACHE_STORE'])->toBe('array')->and($env['SESSION_DRIVER'])->toBe('array')->and($env['QUEUE_CONNECTION'])->toBe('sync')
            ->and($env['BROADCAST_CONNECTION'])->toBe('null')->and($env['MAIL_MAILER'])->toBe('array')->and($env['LAMMA_ALLOW_DESTRUCTIVE'])->toBe('false')
            ->and($env['APP_KEY'])->toStartWith('base64:')->and($env->has('DB_PASSWORD'))->toBeFalse()->and($env->has('DB_HOST'))->toBeFalse();
        expect(file_get_contents(base_path('.env.testing')))->not->toContain('mysql');
    });

    it('is what `--env=testing` loads: a fresh process with nothing else set comes up on SQLite in memory, never MySQL', function () {
        $process = new SymfonyProcess([PHP_BINARY, 'artisan', 'config:show', 'database.default', '--env=testing'], base_path(), ['DB_CONNECTION' => false, 'DB_DATABASE' => false, 'APP_ENV' => false, 'DB_HOST' => false]);
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())->and(trim($process->getOutput()))->toContain('sqlite')->not->toContain('mysql');
    });

    it('is committed: git does not ignore it', function () {
        expect(file_get_contents(base_path('.gitignore')))->not->toMatch('/^\.env\.testing$/m')->and(file_get_contents(base_path('.gitignore')))->not->toMatch('/^\.env\.\*/m');
    });
});

describe('destructive commands', function () {
    it('are switched off on a development machine by default, on the live site always, and allowed in the testing environment', function (string $environment, bool $flag, bool $prohibited) {
        bootDestructiveRule($environment, $flag);

        expect(destructiveCommandsProhibited())->toBe($prohibited);
    })->with([
        'local, no flag' => ['local', false, true],
        'local, flag on' => ['local', true, false],
        'production, no flag' => ['production', false, true],
        'production, flag on (never)' => ['production', true, true],
        'testing' => ['testing', false, false],
        'testing, flag on' => ['testing', true, false],
    ]);

    it('refuse to run when switched off: migrate:fresh does nothing and says so', function () {
        bootDestructiveRule('local', false);

        $this->artisan('migrate:fresh')->expectsOutputToContain('prohibited')->assertFailed();
        $this->artisan('db:wipe')->expectsOutputToContain('prohibited')->assertFailed();
    });

    it('is off by default in the config and in both env examples', function () {
        expect(config('lamma.allow_destructive'))->toBeFalse();

        foreach (['.env.example', '.env.production.example', '.env.testing'] as $file) {
            expect(file_get_contents(base_path($file)))->toContain('LAMMA_ALLOW_DESTRUCTIVE=false');
        }
    });

    it('still lets the test suite refresh its own database (RefreshDatabase runs under the testing environment)', function () {
        expect(app()->environment('testing'))->toBeTrue()->and(destructiveCommandsProhibited())->toBeFalse();
    });

    it('is documented where a person will look, with the one-line reset', function () {
        $readme = file_get_contents(base_path('README.md'));

        expect($readme)->toContain('LAMMA_ALLOW_DESTRUCTIVE')->toContain('lamma:backup-local')->toContain('migrate:fresh');
    });
});

describe('lamma:backup-local', function () {
    beforeEach(function () {
        // A connection of its own: the default one (SQLite in memory) is never touched, so a test can never reach a real MySQL.
        config(['database.connections.dumpable' => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3307, 'database' => 'quiz_party', 'username' => 'quizroot', 'password' => 's3cret-pw',
        ], 'lamma.mysqldump_path' => 'C:/tools/mysqldump.exe']);
        $this->dir = sys_get_temp_dir().'/lamma-backup-'.uniqid();
    });

    afterEach(fn () => File::deleteDirectory($this->dir));

    it('dumps the local MySQL database to a file, with the password in the environment and never on the command line', function () {
        Process::fake(function ($process) {
            $file = collect($process->command)->first(fn ($part) => str_starts_with($part, '--result-file='));
            file_put_contents(substr($file, strlen('--result-file=')), '-- dump');

            return Process::result('');
        });
        $path = $this->dir.'/dump.sql';

        $this->artisan('lamma:backup-local', ['--connection' => 'dumpable', '--path' => $path])->expectsOutputToContain('Dumped "quiz_party"')->assertSuccessful();

        Process::assertRan(function ($process) use ($path) {
            $command = $process->command;

            return $command[0] === 'C:/tools/mysqldump.exe' && in_array('--single-transaction', $command, true) && in_array('--host=127.0.0.1', $command, true)
                && in_array('--port=3307', $command, true) && in_array('--user=quizroot', $command, true) && in_array('--result-file='.$path, $command, true) && end($command) === 'quiz_party'
                && ! str_contains(implode(' ', $command), 's3cret-pw') && ($process->environment['MYSQL_PWD'] ?? null) === 's3cret-pw';
        });
        expect($path)->toBeFile();
    });

    it('writes under storage/app/backups by default, named for the database and the moment', function () {
        Process::fake();

        $this->artisan('lamma:backup-local', ['--connection' => 'dumpable'])->assertSuccessful();

        Process::assertRan(fn ($process) => collect($process->command)->contains(fn ($part) => preg_match('#^--result-file=.*storage[/\\\\]app[/\\\\]backups[/\\\\]local-quiz_party-\d{8}-\d{6}\.sql$#', $part) === 1));
    });

    it('says what to run by hand when mysqldump fails', function () {
        Process::fake(fn () => Process::result(errorOutput: 'mysqldump: command not found', exitCode: 127));

        $this->artisan('lamma:backup-local', ['--connection' => 'dumpable', '--path' => $this->dir.'/x.sql'])->expectsOutputToContain('mysqldump failed')->expectsOutputToContain('mysqldump -u root -p --single-transaction')->assertFailed();
    });

    it('refuses on SQLite (copy the file instead) and in production (the server has its own backup)', function () {
        Process::fake();

        // The application's own connection here is SQLite in memory.
        $this->artisan('lamma:backup-local')->expectsOutputToContain('only MySQL')->assertFailed();

        app()['env'] = 'production';
        $this->artisan('lamma:backup-local', ['--connection' => 'dumpable'])->expectsOutputToContain('deploy/backup.sh')->assertFailed();

        Process::assertNothingRan();
    });

    it('finds mysqldump: the configured path, a WAMP install next to this PHP, or the PATH', function () {
        expect(BackupLocalCommand::binary())->toBe('C:/tools/mysqldump.exe');

        config(['lamma.mysqldump_path' => null]);
        expect(BackupLocalCommand::binary() === 'mysqldump' || str_ends_with(BackupLocalCommand::binary(), 'mysqldump.exe'))->toBeTrue();
    });
});
