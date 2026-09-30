<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locale precedence: session → user's preferred_locale → Accept-Language → app default.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = array_keys(config('locales.supported'));

        $locale = collect([
            $request->session()->get('locale'),
            $request->user()?->preferred_locale,
            ...$this->browserLocales($request),
        ])->first(fn ($candidate) => in_array($candidate, $supported, true))
            ?? config('app.locale');

        app()->setLocale($locale);

        return $next($request);
    }

    /** @return list<string> Accept-Language codes in preference order, reduced to 2 letters ("en-US" → "en"). */
    private function browserLocales(Request $request): array
    {
        return array_map(fn (string $language) => strtolower(substr($language, 0, 2)), $request->getLanguages());
    }
}
