<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The admin panel UI is always English, regardless of the visitor's session locale. */
class UseEnglishLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale('en');

        return $next($request);
    }
}
