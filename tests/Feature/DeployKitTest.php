<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

function deployFile(string $path): string
{
    return file_get_contents(base_path($path));
}

/** Position of $needle in $haystack, failing the test with a clear message when it is missing. */
function positionOf(string $haystack, string $needle): int
{
    $position = strpos($haystack, $needle);
    expect($position)->not->toBeFalse("missing in the file: {$needle}");

    return (int) $position;
}

function bashAvailable(): bool
{
    static $available;

    return $available ??= (new Process(['bash', '-c', 'echo ok']))->run() === 0;
}

describe('the nginx site', function () {
    it('redirects http to https and www to the bare domain', function () {
        $conf = deployFile('deploy/nginx/lamma.conf');

        expect($conf)->toContain('listen 80;')->toContain('return 301 https://lamma.example$request_uri;')
            ->toContain('listen 443 ssl;')->toContain('/etc/letsencrypt/live/lamma.example/fullchain.pem')->toContain('location /.well-known/acme-challenge/');
        expect(substr_count($conf, '{'))->toBe(substr_count($conf, '}'));
    });

    it('runs PHP through the Lamma FPM pool, and only index.php', function () {
        $conf = deployFile('deploy/nginx/lamma.conf');

        expect($conf)->toContain('fastcgi_pass unix:/run/php/php8.3-fpm-lamma.sock;')->toContain('location = /index.php')->toContain('location ~ \.php$ {')
            ->toContain('try_files $uri $uri/ /index.php?$query_string;')->toContain('root /var/www/lamma/public;');
        expect(deployFile('deploy/php-fpm/lamma-pool.conf'))->toContain('listen = /run/php/php8.3-fpm-lamma.sock')->toContain('user = lamma');
    });

    it('caches /build for a year and /brand for a month, compresses text and takes uploads', function () {
        $conf = deployFile('deploy/nginx/lamma.conf');

        expect($conf)->toContain('location ^~ /build/')->toContain('expires 1y;')->toContain('immutable')
            ->toContain('location ^~ /brand/')->toContain('expires 30d;')
            ->toContain('gzip on;')->toContain('client_max_body_size 8M;');
    });

    it('proxies the Reverb WebSocket on /app and /apps with the upgrade headers and long timeouts', function () {
        $conf = deployFile('deploy/nginx/lamma.conf');

        foreach (['/app', '/apps'] as $path) {
            $block = substr($conf, positionOf($conf, "location {$path} {"));
            $block = substr($block, 0, strpos($block, "\n    }") + 6);

            expect($block)->toContain('proxy_pass http://127.0.0.1:8080;')->toContain('proxy_http_version 1.1;')
                ->toContain('proxy_set_header Upgrade $http_upgrade;')->toContain('proxy_set_header Connection $lamma_connection_upgrade;')
                ->toContain('proxy_read_timeout 3600s;')->toContain('proxy_set_header X-Forwarded-Proto $scheme;');
        }
        expect($conf)->toContain('map $http_upgrade $lamma_connection_upgrade');
    });

    it('has a port-80 bootstrap file to get the first certificate', function () {
        $conf = deployFile('deploy/nginx/lamma-http-only.conf');

        expect($conf)->toContain('listen 80;')->toContain('/.well-known/acme-challenge/')->not->toContain('443');
    });
});

describe('Supervisor and cron', function () {
    it('runs the queue worker as specified', function () {
        $conf = deployFile('deploy/supervisor/lamma-worker.conf');

        expect($conf)->toContain('queue:work redis --sleep=1 --tries=3 --max-time=3600')->toContain('autorestart=true')->toContain('user=lamma')->toContain('numprocs=2');
    });

    it('runs Reverb on loopback port 8080', function () {
        $conf = deployFile('deploy/supervisor/lamma-reverb.conf');

        expect($conf)->toContain('reverb:start --host=127.0.0.1 --port=8080')->toContain('autorestart=true')->toContain('user=lamma');
    });

    it('runs the scheduler every minute and the backup every night', function () {
        $cron = deployFile('deploy/cron/lamma');

        expect($cron)->toContain('* * * * * lamma cd /var/www/lamma && /usr/bin/php artisan schedule:run')->toContain('30 2 * * * lamma /bin/bash /var/www/lamma/deploy/backup.sh');
    });
});

describe('deploy/deploy.sh', function () {
    it('is valid bash, strict, and wrapped in main() so a git pull cannot change it mid-run', function () {
        $script = deployFile('deploy/deploy.sh');

        expect($script)->toStartWith('#!/usr/bin/env bash')->toContain('set -Eeuo pipefail')->toContain("main \"\$@\"\nexit");
        if (bashAvailable()) {
            expect((new Process(['bash', '-n', base_path('deploy/deploy.sh')]))->run())->toBe(0);
            expect((new Process(['bash', '-n', base_path('deploy/backup.sh')]))->run())->toBe(0);
        }
    });

    it('runs the steps in the specified order', function () {
        $script = preg_replace('/^\s*#.*$/m', '', deployFile('deploy/deploy.sh'));      // the header comment lists the steps too
        $steps = [
            'artisan down --render="errors::503" --retry=15', 'git pull --ff-only', 'install --no-dev --optimize-autoloader', 'npm ci', 'npm run build',
            'artisan migrate --force', 'artisan db:seed --class=ContentSeeder --force', 'artisan storage:link', 'artisan optimize', 'artisan queue:restart',
            'artisan reverb:restart', "artisan up\n    down=0", 'artisan lamma:doctor',
        ];

        $last = -1;
        foreach ($steps as $step) {
            $position = positionOf($script, $step);
            expect($position)->toBeGreaterThan($last, $step);
            $last = $position;
        }
    });

    it('brings the site back up when a step fails', function () {
        expect(deployFile('deploy/deploy.sh'))->toContain('trap finish EXIT')->toContain('"$php" artisan up || true');
    });

    it('is executable in git', function () {
        $listing = new Process(['git', 'ls-files', '--stage', 'deploy/deploy.sh', 'deploy/backup.sh'], base_path());
        if ($listing->run() !== 0 || trim($listing->getOutput()) === '') {
            $this->markTestSkipped('not in a git checkout, or not committed yet');
        }

        foreach (explode("\n", trim($listing->getOutput())) as $line) {
            expect($line)->toStartWith('100755');
        }
    });

    describe('really run against a throwaway checkout', function () {
        /**
         * An app folder that is a git clone of a bare "origin", with stand-in php, composer and npm that only write what they were asked to do
         * into a log (and fail on demand), so deploy.sh itself is what runs.
         *
         * @return array{app: string, origin: string, bin: string, log: string, work: string}
         */
        function fakeServer(): array
        {
            $root = str_replace('\\', '/', sys_get_temp_dir()).'/lamma-deploy-'.uniqid();
            $dirs = ['origin' => "{$root}/origin.git", 'work' => "{$root}/work", 'app' => "{$root}/app", 'bin' => "{$root}/bin"];
            foreach ($dirs as $dir) {
                mkdir($dir, 0777, true);
            }
            $log = "{$root}/commands.log";

            $git = fn (string $cwd, array $args) => (new Process(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', ...$args], $cwd))->mustRun();
            $git($dirs['origin'], ['init', '--bare', '-b', 'main']);
            $git($dirs['work'], ['clone', $dirs['origin'], '.']);
            $git($dirs['work'], ['checkout', '-b', 'main']);
            File::ensureDirectoryExists("{$dirs['work']}/deploy");
            copy(base_path('deploy/deploy.sh'), "{$dirs['work']}/deploy/deploy.sh");
            file_put_contents("{$dirs['work']}/version.txt", "1\n");
            $git($dirs['work'], ['add', '-A']);
            $git($dirs['work'], ['commit', '-m', 'first']);
            $git($dirs['work'], ['push', '-u', 'origin', 'main']);
            $git($root, ['clone', $dirs['origin'], 'app-clone']);
            File::deleteDirectory($dirs['app']);
            rename("{$root}/app-clone", $dirs['app']);
            file_put_contents("{$dirs['app']}/.env", "APP_ENV=production\n");
            mkdir("{$dirs['app']}/vendor");

            // Stand-ins: each records "<tool> <args>" and exits 1 when FAIL_ON is a substring of that line.
            foreach (['php', 'composer', 'npm'] as $tool) {
                file_put_contents("{$dirs['bin']}/{$tool}", "#!/usr/bin/env bash\nline=\"{$tool} \$*\"\necho \"\$line\" >> \"{$log}\"\n[ -n \"\${FAIL_ON:-}\" ] && [[ \"\$line\" == *\"\$FAIL_ON\"* ]] && exit 1\nexit 0\n");
                chmod("{$dirs['bin']}/{$tool}", 0755);
            }

            return [...$dirs, 'log' => $log, 'root' => $root];
        }

        function runDeploy(array $server, array $args = [], array $env = []): Process
        {
            $process = new Process(['bash', "{$server['app']}/deploy/deploy.sh", ...$args], $server['app'], [
                'APP_PATH' => $server['app'], 'PHP_BIN' => "{$server['bin']}/php", 'COMPOSER_BIN' => "{$server['bin']}/composer",
                'PATH' => $server['bin'].PATH_SEPARATOR.getenv('PATH'), ...$env,
            ]);
            $process->run();

            return $process;
        }

        function commandsRun(array $server): array
        {
            return is_file($server['log']) ? array_values(array_filter(explode("\n", file_get_contents($server['log'])))) : [];
        }

        afterEach(function () {
            foreach (glob(sys_get_temp_dir().'/lamma-deploy-*') as $dir) {
                File::deleteDirectory($dir);
            }
        });

        it('pulls the new code and runs every command in order, finishing with the doctor', function () {
            if (! bashAvailable()) {
                $this->markTestSkipped('bash is not available');
            }
            $server = fakeServer();
            file_put_contents("{$server['work']}/version.txt", "2\n");
            (new Process(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-am', 'second'], $server['work']))->mustRun();
            (new Process(['git', 'push'], $server['work']))->mustRun();

            $process = runDeploy($server);

            expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
            expect(trim(file_get_contents("{$server['app']}/version.txt")))->toBe('2');
            expect(commandsRun($server))->toBe([
                'php artisan down --render=errors::503 --retry=15',
                'composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist',
                'npm ci --no-audit --no-fund',
                'npm run build',
                'php artisan migrate --force',
                'php artisan db:seed --class=ContentSeeder --force',
                'php artisan storage:link',
                'php artisan optimize',
                'php artisan queue:restart',
                'php artisan reverb:restart',
                'php artisan up',
                'php artisan lamma:doctor',
            ]);
        });

        it('stops at the first error and brings the site back up', function () {
            if (! bashAvailable()) {
                $this->markTestSkipped('bash is not available');
            }
            $server = fakeServer();

            $process = runDeploy($server, env: ['FAIL_ON' => 'artisan migrate']);

            expect($process->getExitCode())->not->toBe(0)->and($process->getErrorOutput())->toContain('Deploy FAILED');
            $commands = commandsRun($server);
            expect($commands)->toContain('php artisan down --render=errors::503 --retry=15')->toContain('php artisan migrate --force')
                ->not->toContain('php artisan db:seed --class=ContentSeeder --force')->not->toContain('php artisan lamma:doctor')
                ->and(end($commands))->toBe('php artisan up');
            expect(array_count_values($commands)['php artisan up'])->toBe(1);
        });

        it('does not touch git with --no-pull, for rollbacks', function () {
            if (! bashAvailable()) {
                $this->markTestSkipped('bash is not available');
            }
            $server = fakeServer();
            file_put_contents("{$server['work']}/version.txt", "2\n");
            (new Process(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-am', 'second'], $server['work']))->mustRun();
            (new Process(['git', 'push'], $server['work']))->mustRun();
            (new Process(['git', 'checkout', '--detach', 'HEAD'], $server['app']))->mustRun();      // what a rollback leaves: no branch to pull

            $process = runDeploy($server, ['--no-pull']);

            expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
            expect(trim(file_get_contents("{$server['app']}/version.txt")))->toBe('1');               // nothing was pulled
            expect($process->getOutput())->toContain('Skipping git pull');
        });

        it('refuses to run without a .env', function () {
            if (! bashAvailable()) {
                $this->markTestSkipped('bash is not available');
            }
            $server = fakeServer();
            unlink("{$server['app']}/.env");

            $process = runDeploy($server);

            expect($process->getExitCode())->not->toBe(0)->and($process->getErrorOutput())->toContain('No .env');
            expect(commandsRun($server))->toBe([]);
        });

        it('rejects an option it does not know', function () {
            if (! bashAvailable()) {
                $this->markTestSkipped('bash is not available');
            }

            expect(runDeploy(fakeServer(), ['--wat'])->getExitCode())->toBe(2);
        });
    });
});

describe('the GitHub workflows', function () {
    it('pin every action to a full commit SHA', function (string $file) {
        $yaml = deployFile($file);
        preg_match_all('/uses:\s*(\S+)/', $yaml, $uses);

        expect($uses[1])->not->toBeEmpty();
        foreach ($uses[1] as $use) {
            expect($use)->toMatch('/^[\w.-]+\/[\w.-]+@[0-9a-f]{40}$/', "not pinned: {$use}");
        }
    })->with(['.github/workflows/tests.yml', '.github/workflows/deploy.yml']);

    it('deploy only after the tests workflow succeeded on a push to main, over SSH', function () {
        $workflow = Yaml::parse(deployFile('.github/workflows/deploy.yml'));

        expect($workflow['on']['workflow_run'])->toMatchArray(['workflows' => ['tests'], 'types' => ['completed'], 'branches' => ['main']]);
        $job = $workflow['jobs']['deploy'];
        expect($job['if'])->toContain("conclusion == 'success'")->toContain("event == 'push'");
        expect($job['steps'][0]['uses'])->toStartWith('appleboy/ssh-action@');

        $with = $job['steps'][0]['with'];
        expect($with['host'])->toBe('${{ secrets.SSH_HOST }}')->and($with['username'])->toBe('${{ secrets.SSH_USER }}')
            ->and($with['key'])->toBe('${{ secrets.SSH_PRIVATE_KEY }}')->and($with['port'])->toBe('${{ secrets.SSH_PORT }}')
            ->and($job['steps'][0]['env']['APP_PATH'])->toBe('${{ secrets.APP_PATH }}')->and($with['script'])->toContain('bash deploy/deploy.sh')
            ->and($with['script_stop'])->toBeTrue();
        expect($workflow['concurrency']['cancel-in-progress'])->toBeFalse();
    });

    it('run the browser tests in their own job with the Playwright install step', function () {
        $workflow = Yaml::parse(deployFile('.github/workflows/tests.yml'));

        expect(array_keys($workflow['jobs']))->toBe(['ci', 'browser']);
        $steps = collect($workflow['jobs']['browser']['steps']);
        expect($steps->pluck('run')->filter()->implode("\n"))->toContain('npx playwright install --with-deps chromium')->toContain('vendor/bin/pest tests/Browser');
        // The fast job does not run them.
        expect(collect($workflow['jobs']['ci']['steps'])->pluck('run')->filter()->implode("\n"))->not->toContain('tests/Browser');
    });

    it('need no database server', function () {
        $workflow = Yaml::parse(deployFile('.github/workflows/tests.yml'));

        expect($workflow['env'])->toMatchArray(['DB_CONNECTION' => 'sqlite', 'QUEUE_CONNECTION' => 'sync']);
    });
});

describe('docs/deployment.md', function () {
    it('covers the whole path from a fresh server to a rollback', function () {
        $docs = deployFile('docs/deployment.md');

        foreach ([
            'Ubuntu 24.04', 'php8.3-fpm', 'Node 22', 'mysql-server', 'redis-server', 'nginx', 'supervisor', 'certbot',
            'deploy/nginx/lamma.conf', 'deploy/supervisor/lamma-worker.conf', 'deploy/supervisor/lamma-reverb.conf', 'deploy/cron/lamma', 'deploy/deploy.sh', 'deploy/backup.sh',
            'schedule:run', '.env.production.example', 'php artisan key:generate', 'db:seed --class=AdminSeeder --force', 'lamma:doctor',
            'SSH_HOST', 'SSH_USER', 'SSH_PRIVATE_KEY', 'SSH_PORT', 'APP_PATH',
            'mysqldump', '7 days', '--no-pull', 'git checkout', 'storage/logs/laravel-', 'mobile data', 'lamma.example',
        ] as $needle) {
            expect($docs)->toContain($needle);
        }
    });

    it('only mentions files that exist', function () {
        preg_match_all('/`((?:deploy|docs|\.github|config|public|storage)\/[\w.\/-]*[\w])`/', deployFile('docs/deployment.md'), $paths);

        foreach (array_unique($paths[1]) as $path) {
            if (str_contains($path, 'storage/logs') || str_contains($path, 'public/storage') || $path === 'public/brand' || str_ends_with($path, '/')) {
                continue;
            }
            expect(file_exists(base_path($path)) || is_dir(base_path($path)))->toBeTrue("docs/deployment.md mentions {$path}");
        }
    });

    it('uses the one placeholder domain everywhere', function () {
        foreach (['deploy/nginx/lamma.conf', 'deploy/nginx/lamma-http-only.conf', '.env.production.example'] as $file) {
            expect(deployFile($file))->toContain('lamma.example');
        }
    });
});
