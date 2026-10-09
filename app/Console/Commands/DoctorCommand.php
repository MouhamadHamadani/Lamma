<?php

namespace App\Console\Commands;

use App\Enums\Difficulty;
use App\Models\Admin;
use App\Models\Category;
use App\Models\User;
use App\Support\BrandFiles;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

#[Signature('lamma:doctor')]
#[Description('Check the production setup (pass / warn / fail per item). Exits with code 1 when anything fails')]
class DoctorCommand extends Command
{
    private const PASS = 'pass';

    private const WARN = 'warn';

    private const FAIL = 'fail';

    /** Every category needs at least this many questions on every difficulty, so a 10-question game never runs out. */
    private const MIN_QUESTIONS = 10;

    public function handle(): int
    {
        $rows = [];
        foreach ($this->checks() as $label => $check) {
            try {
                [$status, $detail] = $check();
            } catch (Throwable $e) {
                [$status, $detail] = [self::FAIL, 'The check itself crashed: '.$e->getMessage()];
            }
            $rows[] = [$label, $status, $detail];
        }

        $this->table(['Check', 'Status', 'Details'], array_map(fn (array $row) => [$row[0], $this->paint($row[1]), $row[2]], $rows));

        $count = fn (string $status) => count(array_filter($rows, fn (array $row) => $row[1] === $status));
        $this->line(sprintf('%d pass, %d warn, %d fail.', $count(self::PASS), $count(self::WARN), $count(self::FAIL)));

        if ($count(self::FAIL) > 0) {
            $this->error('The setup has failing checks. Fix them before going live.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return array<string, callable(): array{0: string, 1: string}> */
    private function checks(): array
    {
        return [
            'Environment' => fn () => app()->isProduction()
                ? [self::PASS, 'APP_ENV=production']
                : [self::WARN, 'APP_ENV is "'.app()->environment().'"; the live site should use production'],
            'APP_KEY' => fn () => filled(config('app.key')) ? [self::PASS, 'set'] : [self::FAIL, 'empty: run php artisan key:generate'],
            'Debug off' => fn () => config('app.debug') ? [self::FAIL, 'APP_DEBUG=true would show stack traces to visitors'] : [self::PASS, 'APP_DEBUG=false'],
            'APP_URL is https' => $this->appUrl(...),
            'Database' => $this->database(...),
            'Redis' => $this->redis(...),
            'Queue is not sync' => fn () => config('queue.default') === 'sync'
                ? [self::FAIL, 'QUEUE_CONNECTION=sync runs jobs inside the request and the game timers never fire on time']
                : [self::PASS, 'QUEUE_CONNECTION='.config('queue.default')],
            'Reverb config' => $this->reverbConfig(...),
            'Reverb is listening' => $this->reverbPort(...),
            'Scheduler' => $this->scheduler(...),
            'Mail' => fn () => in_array(config('mail.default'), ['log', 'array'], true)
                ? [self::FAIL, 'MAIL_MAILER='.config('mail.default').': password reset emails would never be delivered']
                : [self::PASS, 'MAIL_MAILER='.config('mail.default')],
            'Session cookie' => fn () => config('session.secure') ? [self::PASS, 'secure'] : [self::WARN, 'SESSION_SECURE_COOKIE is not true'],
            'Storage link' => fn () => file_exists(public_path('storage')) ? [self::PASS, 'public/storage exists'] : [self::FAIL, 'missing: run php artisan storage:link'],
            'Storage is writable' => $this->writable(...),
            'Front-end build' => fn () => is_file(public_path('build/manifest.json')) ? [self::PASS, 'public/build/manifest.json exists'] : [self::FAIL, 'missing: run npm run build'],
            'Optimized' => fn () => app()->configurationIsCached() && app()->routesAreCached()
                ? [self::PASS, 'config and routes are cached']
                : [self::WARN, 'run php artisan optimize'],
            'Questions' => $this->questions(...),
            'Active admin' => fn () => Admin::where('is_active', true)->exists()
                ? [self::PASS, 'at least one active admin']
                : [self::WARN, 'no active admin: run php artisan db:seed --class=AdminSeeder --force'],
            'No test players' => $this->testPlayers(...),
            'Official brand and sound files' => $this->placeholders(...),
        ];
    }

    /** @return array{0: string, 1: string} */
    private function appUrl(): array
    {
        $url = (string) config('app.url');

        if (! str_starts_with($url, 'https://')) {
            return [self::FAIL, "APP_URL is \"{$url}\": it must start with https://"];
        }
        if (str_contains($url, 'lamma.example')) {
            return [self::WARN, "{$url} is still the placeholder domain"];
        }

        return [self::PASS, $url];
    }

    /** @return array{0: string, 1: string} */
    private function database(): array
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            return [self::FAIL, 'cannot connect: '.$e->getMessage()];
        }

        if (! Schema::hasTable('rooms') || ! Schema::hasTable('questions')) {
            return [self::FAIL, 'connected, but the tables are missing: run php artisan migrate --force'];
        }

        return [self::PASS, config('database.default').' reachable, tables present'];
    }

    /** @return array{0: string, 1: string} */
    private function redis(): array
    {
        $uses = array_keys(array_filter([
            'cache' => config('cache.default') === 'redis',
            'queue' => config('queue.default') === 'redis',
            'session' => config('session.driver') === 'redis',
        ]));

        if ($uses === []) {
            return [self::WARN, 'not used (cache, queue and session are not on redis); production is meant to use it'];
        }

        try {
            Redis::connection()->ping();
        } catch (Throwable $e) {
            return [self::FAIL, 'used for '.implode(', ', $uses).' but unreachable: '.$e->getMessage()];
        }

        return [self::PASS, 'reachable, used for '.implode(', ', $uses)];
    }

    /** @return array{0: string, 1: string} */
    private function reverbConfig(): array
    {
        if (config('broadcasting.default') !== 'reverb') {
            return [self::FAIL, 'BROADCAST_CONNECTION is "'.config('broadcasting.default').'", not reverb'];
        }

        $missing = array_keys(array_filter([
            'REVERB_APP_ID' => blank(config('broadcasting.connections.reverb.app_id')),
            'REVERB_APP_KEY' => blank(config('broadcasting.connections.reverb.key')),
            'REVERB_APP_SECRET' => blank(config('broadcasting.connections.reverb.secret')),
            'REVERB_HOST' => blank(config('broadcasting.connections.reverb.options.host')),
            'REVERB_SERVER_PORT' => blank(config('reverb.servers.reverb.port')),
        ]));
        if ($missing !== []) {
            return [self::FAIL, 'not set: '.implode(', ', $missing)];
        }

        $host = (string) config('broadcasting.connections.reverb.options.host');
        $scheme = (string) config('broadcasting.connections.reverb.options.scheme');
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true) || $scheme !== 'https') {
            return [self::WARN, "REVERB_HOST={$host} REVERB_SCHEME={$scheme}: phones need the public host over https (wss) behind nginx"];
        }

        return [self::PASS, "browsers connect to wss://{$host}:".config('broadcasting.connections.reverb.options.port')];
    }

    /** @return array{0: string, 1: string} */
    private function reverbPort(): array
    {
        $host = (string) (config('reverb.servers.reverb.host') ?: '127.0.0.1');
        $port = (int) config('reverb.servers.reverb.port');
        $connectTo = $host === '0.0.0.0' ? '127.0.0.1' : $host;

        $socket = @fsockopen($connectTo, $port, $errorCode, $error, 2);
        if ($socket === false) {
            return [self::FAIL, "nothing is listening on {$connectTo}:{$port} ({$error}): is the lamma-reverb Supervisor program running?"];
        }
        fclose($socket);

        return [self::PASS, "{$connectTo}:{$port} accepts connections"];
    }

    /** @return array{0: string, 1: string} */
    private function scheduler(): array
    {
        $last = Cache::get(config('lamma.heartbeat_key'));
        $maxHours = (int) config('lamma.scheduler_max_hours');

        if ($last === null) {
            return [self::WARN, 'no heartbeat yet: lamma:prune has not run since the cache was created (normal right after the first deploy). Check the schedule:run cron line'];
        }

        $hours = ((int) now()->timestamp - (int) $last) / 3600;
        if ($hours > $maxHours) {
            return [self::FAIL, sprintf('the last scheduled run was %.1f hours ago (limit %d): is the schedule:run cron line in place?', $hours, $maxHours)];
        }

        return [self::PASS, sprintf('ran %.1f hours ago', $hours)];
    }

    /** @return array{0: string, 1: string} */
    private function writable(): array
    {
        $blocked = array_filter([storage_path('logs'), storage_path('framework'), base_path('bootstrap/cache')], fn (string $path) => ! is_writable($path));

        return $blocked === [] ? [self::PASS, 'storage/ and bootstrap/cache are writable'] : [self::FAIL, 'not writable: '.implode(', ', $blocked)];
    }

    /** @return array{0: string, 1: string} */
    private function questions(): array
    {
        $categories = Category::query()->where('is_active', true)->get();
        if ($categories->isEmpty()) {
            return [self::FAIL, 'no active categories: run php artisan db:seed --class=ContentSeeder --force'];
        }

        $counts = DB::table('questions')->whereNull('deleted_at')->where('is_active', true)
            ->select('category_id', 'difficulty', DB::raw('count(*) as total'))->groupBy('category_id', 'difficulty')->get()
            ->mapWithKeys(fn ($row) => [$row->category_id.'/'.$row->difficulty => (int) $row->total]);

        $short = [];
        foreach ($categories as $category) {
            foreach (Difficulty::cases() as $difficulty) {
                $have = $counts[$category->id.'/'.$difficulty->value] ?? 0;
                if ($have < self::MIN_QUESTIONS) {
                    $short[] = "{$category->slug} {$difficulty->value} ({$have})";
                }
            }
        }

        return $short === []
            ? [self::PASS, $categories->count().' categories, at least '.self::MIN_QUESTIONS.' questions on every difficulty']
            : [self::FAIL, 'fewer than '.self::MIN_QUESTIONS.': '.implode(', ', $short)];
    }

    /** @return array{0: string, 1: string} */
    private function testPlayers(): array
    {
        $exists = User::query()->whereIn('email', ['test@example.com', 'english@example.com'])->exists();

        if (! $exists) {
            return [self::PASS, 'the seeded test accounts do not exist'];
        }

        return app()->isProduction()
            ? [self::FAIL, 'test@example.com / english@example.com exist (password "password"): delete them']
            : [self::WARN, 'the seeded test accounts exist (fine locally, never on the live site)'];
    }

    /** @return array{0: string, 1: string} */
    private function placeholders(): array
    {
        $still = BrandFiles::stillPlaceholders();

        return $still === []
            ? [self::PASS, 'the placeholder logo, icons and sounds have been replaced']
            : [self::WARN, count($still).' file(s) are still the placeholders: '.implode(', ', $still)];
    }

    private function paint(string $status): string
    {
        return match ($status) {
            self::PASS => '<fg=green>PASS</>',
            self::WARN => '<fg=yellow>WARN</>',
            default => '<fg=red>FAIL</>',
        };
    }
}
