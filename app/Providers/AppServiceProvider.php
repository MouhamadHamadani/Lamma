<?php

namespace App\Providers;

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
        //
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

    /** Guests are not Users: the "player" guard turns the lamma_guest cookie into their RoomPlayer for channel auth. */
    protected function configureGuards(): void
    {
        Auth::viaRequest('player', fn (Request $request) => app(RoomPresence::class)->guestPlayerFor($request->input('channel_name')));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Western digits (0-9) in every locale, including Arabic.
        Number::useLocale('en');

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

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
