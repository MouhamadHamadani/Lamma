<?php

namespace App\Listeners;

use App\Game\PlayerIdentity;
use App\Support\Analytics;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;

/**
 * Analytics: a new account is a `sign_up` and a returning one a `login`, reported by the next page (App\Support\Analytics). Registering also logs the
 * user in, which is not a second event. Only players and hosts count (the `web` guard), never the admin panel.
 */
class TrackSignIn
{
    public function handle(Login|Registered $event): void
    {
        if ($event instanceof Login && $event->guard !== 'web') {
            return;
        }

        Analytics::flash($event instanceof Registered ? 'sign_up' : 'login');

        // Someone who signed up in order to host: the page they were heading for was room creation ("Host a game"), or this browser had just hosted a demo.
        if ($event instanceof Registered && $this->cameToHost()) {
            Analytics::flash('host_signup');
        }
    }

    private function cameToHost(): bool
    {
        return str_starts_with((string) session('url.intended'), route('rooms.create')) || app(PlayerIdentity::class)->demoHostToken() !== null;
    }
}
