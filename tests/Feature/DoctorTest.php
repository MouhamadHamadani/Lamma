<?php

use App\Enums\Difficulty;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Question;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;

/**
 * Make the app look like a correctly set up production server: production environment, debug off, https URL, asynchronous queue, Redis,
 * Reverb configured and listening, a fresh scheduler heartbeat, real mail, the storage link, the build, the content, an admin and (when
 * $official) replaced brand files. Returns the listening Reverb socket: keep it open for the length of the test.
 *
 * @return resource
 */
function goodProduction(bool $official = true, bool $redisUp = true)
{
    app()['env'] = 'production';

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);

    config([
        'app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.debug' => false, 'app.url' => 'https://lamma.test',
        'cache.default' => 'redis', 'queue.default' => 'redis', 'session.driver' => 'redis', 'session.secure' => true,
        'mail.default' => 'smtp',
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.app_id' => 'app', 'broadcasting.connections.reverb.key' => 'key', 'broadcasting.connections.reverb.secret' => 'secret',
        'broadcasting.connections.reverb.options.host' => 'lamma.test', 'broadcasting.connections.reverb.options.port' => 443, 'broadcasting.connections.reverb.options.scheme' => 'https',
        'reverb.servers.reverb.host' => '127.0.0.1', 'reverb.servers.reverb.port' => $port,
    ]);
    $redisUp
        ? Redis::shouldReceive('connection')->andReturn(Mockery::mock(['ping' => true]))
        : Redis::shouldReceive('connection')->andThrow(new RuntimeException('Connection refused'));
    Cache::driver('array')->forever(config('lamma.heartbeat_key'), now()->timestamp);
    config(['cache.default' => 'array']);                                    // the heartbeat lives in the array cache in a test; Redis is only mocked
    Cache::forever(config('lamma.heartbeat_key'), now()->timestamp);

    // A public/ folder with the storage link, the build and (optionally) the official brand files.
    $public = sys_get_temp_dir().'/lamma-public-'.uniqid();
    mkdir($public.'/build', 0777, true);
    mkdir($public.'/storage');
    file_put_contents($public.'/build/manifest.json', '{}');
    foreach (array_keys(config('lamma.placeholders')) as $path) {
        File::ensureDirectoryExists(dirname("{$public}/{$path}"));
        file_put_contents("{$public}/{$path}", $official ? "the official {$path}" : file_get_contents(public_path($path)));
    }
    app()->usePublicPath($public);
    test()->afterEach(fn () => File::deleteDirectory($public));

    // One category with a full set of questions on every difficulty (the real content has its own tests, and is slow to seed).
    $category = Category::factory()->create(['slug' => 'sports']);
    foreach (Difficulty::cases() as $difficulty) {
        Question::factory()->count(10)->create(['category_id' => $category->id, 'difficulty' => $difficulty]);
    }
    Admin::factory()->create();

    return $socket;
}

/** @return array{0: int, 1: array<string, array{0: string, 1: string}>} the exit code and label => [PASS|WARN|FAIL, details] */
function doctor(): array
{
    $code = Artisan::call('lamma:doctor');
    $rows = [];

    foreach (explode("\n", Artisan::output()) as $line) {
        if (preg_match('/^\|\s*(.+?)\s*\|\s*(PASS|WARN|FAIL)\s*\|\s*(.*?)\s*\|?\s*$/', $line, $match)) {
            $rows[$match[1]] = [$match[2], $match[3]];
        }
    }

    return [$code, $rows];
}

describe('lamma:doctor', function () {
    it('passes on a good production setup, with exit code 0', function () {
        $socket = goodProduction();

        [$code, $rows] = doctor();

        expect($code)->toBe(0);
        foreach (['Environment', 'APP_KEY', 'Debug off', 'APP_URL is https', 'Database', 'Redis', 'Queue is not sync', 'Reverb config', 'Reverb is listening', 'Scheduler', 'Mail',
            'Session cookie', 'Storage link', 'Storage is writable', 'Front-end build', 'Questions', 'Active admin', 'No test players', 'Official brand and sound files'] as $label) {
            expect($rows[$label][0] ?? null)->toBe('PASS', "{$label}: ".($rows[$label][1] ?? 'missing'));
        }
        expect(collect($rows)->pluck(0)->contains('FAIL'))->toBeFalse();
        fclose($socket);
    });

    it('prints a table with a summary line', function () {
        $socket = goodProduction();

        Artisan::call('lamma:doctor');
        $output = Artisan::output();

        expect($output)->toContain('| Check')->toContain('| Status')->toContain('| Details')->toMatch('/\d+ pass, \d+ warn, 0 fail\./');
        fclose($socket);
    });

    it('fails when debug is on', function () {
        $socket = goodProduction();
        config(['app.debug' => true]);

        [$code, $rows] = doctor();

        expect($code)->toBe(1)->and($rows['Debug off'][0])->toBe('FAIL')->and($rows['Debug off'][1])->toContain('APP_DEBUG=true');
        fclose($socket);
    });

    it('warns, and still passes, while the placeholder brand and sound files are in place', function () {
        $socket = goodProduction(official: false);

        [$code, $rows] = doctor();

        expect($code)->toBe(0)->and($rows['Official brand and sound files'][0])->toBe('WARN')
            ->and($rows['Official brand and sound files'][1])->toContain('14 file(s) are still the placeholders')->toContain('brand/lamma-icon.svg')->toContain('sounds/tick.wav');
        fclose($socket);
    });

    it('stops warning about a file once the official one is in place', function () {
        $socket = goodProduction(official: false);
        file_put_contents(public_path('brand/lamma-icon.svg'), '<svg>official</svg>');

        [, $rows] = doctor();

        expect($rows['Official brand and sound files'][1])->toContain('13 file(s)')->not->toContain('brand/lamma-icon.svg');
        fclose($socket);
    });

    it('fails on each problem, with exit code 1', function (string $label, Closure $break) {
        $socket = goodProduction();
        $break();

        [$code, $rows] = doctor();

        expect($code)->toBe(1)->and($rows[$label][0])->toBe('FAIL', $label.': '.$rows[$label][1]);
        fclose($socket);
    })->with([
        'no key' => ['APP_KEY', fn () => config(['app.key' => ''])],
        'http url' => ['APP_URL is https', fn () => config(['app.url' => 'http://lamma.test'])],
        'sync queue' => ['Queue is not sync', fn () => config(['queue.default' => 'sync'])],
        'mail on log' => ['Mail', fn () => config(['mail.default' => 'log'])],
        'no reverb key' => ['Reverb config', fn () => config(['broadcasting.connections.reverb.key' => ''])],
        'broadcast off reverb' => ['Reverb config', fn () => config(['broadcasting.default' => 'null'])],
        'reverb not listening' => ['Reverb is listening', fn () => config(['reverb.servers.reverb.port' => 1])],
        'scheduler silent for two days' => ['Scheduler', fn () => Cache::forever(config('lamma.heartbeat_key'), now()->subDays(2)->timestamp)],
        'no storage link' => ['Storage link', fn () => File::deleteDirectory(public_path('storage'))],
        'no build' => ['Front-end build', fn () => unlink(public_path('build/manifest.json'))],
        'too few questions' => ['Questions', fn () => Question::where('difficulty', 'hard')->forceDelete()],
        'test players exist' => ['No test players', fn () => User::factory()->create(['email' => 'test@example.com'])],
    ]);

    it('names the categories and difficulties that are short of questions', function () {
        $socket = goodProduction();
        $category = Category::where('slug', 'sports')->firstOrFail();
        Question::where('category_id', $category->id)->where('difficulty', 'hard')->take(2)->get()->each->forceDelete();

        [, $rows] = doctor();

        expect($rows['Questions'][0])->toBe('FAIL')->and($rows['Questions'][1])->toContain('sports hard (8)');
        fclose($socket);
    });

    it('fails with no categories at all, pointing at the content seeder', function () {
        $socket = goodProduction();
        Question::query()->forceDelete();
        Category::query()->delete();

        [$code, $rows] = doctor();

        expect($code)->toBe(1)->and($rows['Questions'][1])->toContain('ContentSeeder');
        fclose($socket);
    });

    it('warns when the scheduler has never reported (right after the first deploy), and passes just inside the limit', function () {
        $socket = goodProduction();
        Cache::forget(config('lamma.heartbeat_key'));

        [$code, $rows] = doctor();
        expect($code)->toBe(0)->and($rows['Scheduler'][0])->toBe('WARN');

        Cache::forever(config('lamma.heartbeat_key'), now()->subHours(25)->timestamp);
        expect(doctor()[1]['Scheduler'][0])->toBe('PASS');

        Cache::forever(config('lamma.heartbeat_key'), now()->subHours(27)->timestamp);
        expect(doctor()[1]['Scheduler'][0])->toBe('FAIL');
        fclose($socket);
    });

    it('fails when the database cannot be reached', function () {
        $socket = goodProduction();
        config([
            'database.connections.broken' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'nope', 'username' => 'nope', 'password' => '', 'options' => [PDO::ATTR_TIMEOUT => 1]],
            'database.default' => 'broken',
        ]);
        DB::purge('broken');

        [$code, $rows] = doctor();
        config(['database.default' => 'sqlite']);                       // before the test database rolls back

        expect($code)->toBe(1)->and($rows['Database'][0])->toBe('FAIL')->and($rows['Database'][1])->toContain('cannot connect');
        fclose($socket);
    });

    it('fails when Redis is used but does not answer, and warns when it is not used at all', function () {
        $socket = goodProduction(redisUp: false);
        config(['cache.default' => 'redis']);

        [$code, $rows] = doctor();
        expect($code)->toBe(1)->and($rows['Redis'][0])->toBe('FAIL')->and($rows['Redis'][1])->toContain('unreachable');

        config(['cache.default' => 'array', 'queue.default' => 'database', 'session.driver' => 'array']);
        expect(doctor()[1]['Redis'][0])->toBe('WARN');
        fclose($socket);
    });

    it('warns when this is not the production environment, and when the public Reverb settings are still local', function () {
        $socket = goodProduction();
        app()['env'] = 'local';
        config(['broadcasting.connections.reverb.options.host' => 'localhost', 'broadcasting.connections.reverb.options.scheme' => 'http']);

        [, $rows] = doctor();

        expect($rows['Environment'][0])->toBe('WARN')->and($rows['Reverb config'][0])->toBe('WARN')->and($rows['Reverb config'][1])->toContain('wss');
        fclose($socket);
    });

    it('warns about the placeholder domain', function () {
        $socket = goodProduction();
        config(['app.url' => 'https://lamma.example']);

        [$code, $rows] = doctor();

        expect($code)->toBe(0)->and($rows['APP_URL is https'][0])->toBe('WARN')->and($rows['APP_URL is https'][1])->toContain('placeholder');
        fclose($socket);
    });

    it('is registered, described, and safe to run on a local machine', function () {
        expect(array_keys(Artisan::all()))->toContain('lamma:doctor');

        $code = Artisan::call('lamma:doctor');                          // locally most things fail: it must report, not crash
        expect($code)->toBeIn([0, 1])->and(Artisan::output())->toContain('Check')->toContain('Debug off');
    });
});
