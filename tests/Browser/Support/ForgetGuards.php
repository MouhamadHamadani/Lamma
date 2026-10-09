<?php

namespace Tests\Browser\Support;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * The browser tests serve the app from ONE long-lived process, but a real server builds a fresh app per request. So three things from
 * the previous request would leak into the next one: the session store (Store::loadSession() merges what it reads into whatever is
 * already in memory), the guards' remembered users, and the cookies queued for the response (the jar never empties them, so one
 * browser's guest cookie would be handed to every other browser). When one test drives several browsers as different people, each
 * request must start clean and find out who it is from its own cookies.
 */
class ForgetGuards
{
    public function handle(Request $request, Closure $next): Response
    {
        app('session')->driver()->flush();
        Auth::forgetGuards();
        Cookie::flushQueuedCookies();

        return $next($request);
    }
}
