<?php

namespace App\Support;

use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Throwable;

/**
 * Helpers for the error and maintenance pages (resources/views/errors). These pages must render when the database, the session store
 * or the cache is down, so nothing here touches them: no query, no cache read, no `$request->user()`.
 */
final class ErrorPage
{
    /** What the pages speak when nothing tells them otherwise. */
    public const FALLBACK_LOCALE = 'ar';

    /**
     * The page language: what SetLocale decided for this request (session, then the account, then the browser), else the session on
     * its own, else Accept-Language, else Arabic. The request has no session when the failure happened before or while starting it.
     */
    public static function locale(Request $request): string
    {
        $supported = array_keys(config('locales.supported', []));

        $candidates = [$request->attributes->get('locale')];

        try {
            if ($request->hasSession()) {
                $candidates[] = $request->session()->get('locale');
            }
        } catch (Throwable) {
            // A broken session store: fall through to the browser's language.
        }

        foreach ($request->getLanguages() as $language) {
            $candidates[] = strtolower(substr($language, 0, 2));
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && in_array($candidate, $supported, true)) {
                return $candidate;
            }
        }

        return self::FALLBACK_LOCALE;
    }

    /** Where "Refresh and try again" goes: the page the visitor came from (same site only), else home. */
    public static function backUrl(Request $request): string
    {
        $referer = $request->headers->get('referer');

        return is_string($referer) && parse_url($referer, PHP_URL_HOST) === $request->getHost() ? $referer : route('home');
    }

    /** The stylesheet and font tags, or nothing when the front end has not been built (an error page must never fail to render). */
    public static function assets(): string
    {
        if (! is_file(public_path('build/manifest.json')) && ! is_file(app(Vite::class)->hotFile())) {
            return '';
        }

        try {
            return (string) app(Vite::class)(['resources/css/app.css']).app(Vite::class)->fonts();
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * The built stylesheet itself, for the maintenance page. `php artisan down --render` renders it once, before the deploy rebuilds
     * public/build, so a <link> to a hashed file would 404 for the whole deploy.
     */
    public static function inlineCss(): ?string
    {
        $manifest = public_path('build/manifest.json');
        if (! is_file($manifest)) {
            return null;
        }

        $entry = json_decode((string) file_get_contents($manifest), true)['resources/css/app.css']['file'] ?? null;
        $file = is_string($entry) ? public_path('build/'.$entry) : null;

        return $file !== null && is_file($file) ? (string) file_get_contents($file) : null;
    }
}
