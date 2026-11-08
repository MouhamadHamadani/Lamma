<?php

namespace App\Providers;

use App\Game\NicknameFilter;
use App\Game\RoomPresence;
use App\Models\Room;
use App\Support\ErrorPage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One filter per process: it compiles the blocklist once and remembers its verdicts.
        $this->app->singleton(NicknameFilter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureUrls();
        $this->configureGuards();
        $this->configureRouteBindings();
        $this->configureErrorPages();
        $this->configureDevCommands();
    }

    /** Behind nginx the live site is https only: every generated URL (links, redirects, signed URLs, asset URLs) says so. */
    protected function configureUrls(): void
    {
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }

    /** {room} is a room code, in any letter case. Registered here, not in routes/web.php, so it survives `route:cache`. */
    protected function configureRouteBindings(): void
    {
        Route::bind('room', fn (string $code) => Room::where('code', Str::upper($code))->firstOrFail());
    }

    /** The error pages choose their own language, before they render, without touching the database (see ErrorPage::locale). */
    protected function configureErrorPages(): void
    {
        View::composer('errors::*', fn () => app()->setLocale(ErrorPage::locale(request())));
    }

    /** `composer dev`: check the queue every second (not every three), so a question's reveal and next-question jobs run on time; run the scheduler. */
    protected function configureDevCommands(): void
    {
        DevCommands::artisan('queue:listen --tries=1 --timeout=0 --sleep=1', 'queue');
        // The daily lamma:prune (and anything else scheduled).
        DevCommands::artisan('schedule:work', 'schedule');
    }

    /** Guests are not Users: the "player" guard turns the lamma_guest cookie into their RoomPlayer for channel auth, the "demo" guard the host cookie into a DemoHost. */
    protected function configureGuards(): void
    {
        Auth::viaRequest('player', fn (Request $request) => app(RoomPresence::class)->guestPlayerFor($request->input('channel_name')));
        // The big screen says what it is (a header only the host screen's Echo sends) and is believed only when it really is the host of the channel's room.
        Auth::viaRequest('host', fn (Request $request) => app(RoomPresence::class)->hostScreenFor($request));
        // The host of a demo room has no account either: the host cookie of the room named by the channel makes them its DemoHost.
        Auth::viaRequest('demo', fn (Request $request) => app(RoomPresence::class)->demoHostFor($request->input('channel_name')));
    }

    /**
     * `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback` and `db:wipe` refuse to run on the live site AND on a development machine (the local MySQL
     * holds your seeded content and test accounts), unless the person deliberately set LAMMA_ALLOW_DESTRUCTIVE=true. The testing environment (SQLite in memory, `.env.testing`)
     * is never blocked: Pest's RefreshDatabase and the browser tests need `migrate:fresh`. The one-line reset and the local dump are in CLAUDE.md "Gotchas".
     */
    public function configureDestructiveCommands(): void
    {
        DB::prohibitDestructiveCommands(
            app()->isProduction() || (app()->isLocal() && ! config('lamma.allow_destructive')),
        );
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Western digits (0-9) in every locale, including Arabic.
        Number::useLocale('en');

        $this->configureDestructiveCommands();

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
