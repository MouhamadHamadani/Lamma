<?php

namespace App\Providers;

use App\Game\RoomPresence;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;
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
        $this->configureGuards();
        $this->configureDevCommands();
    }

    /** `composer dev`: check the queue every second (not every three), so a question's reveal and next-question jobs run on time. */
    protected function configureDevCommands(): void
    {
        DevCommands::artisan('queue:listen --tries=1 --timeout=0 --sleep=1', 'queue');
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
