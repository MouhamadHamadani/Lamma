<?php

namespace App\Support;

use Illuminate\Http\Request;
use Livewire\Component;

/**
 * Google Analytics (GA4, gtag.js), kept private by design: players can be children and the pages carry room codes.
 *
 *  - It runs only when GOOGLE_ANALYTICS_ID is a real measurement ID (G- and ten letters or digits), the app is in production, and the page is not
 *    part of the admin panel. It is never in a view's source: the layouts ask here.
 *  - What GA is told about a page is a SANITIZED path made from the route's own pattern ("/host/:room", never "/host/K7MP"), with no query string.
 *  - Events are the fixed list below with fixed, low-cardinality parameters (counts, slugs, language codes). No name, e-mail, user id, guest token,
 *    photo or room code can reach GA: unknown events are dropped and every parameter is checked.
 *  - Nothing is sent before the visitor accepts (Consent Mode v2 defaults to denied; resources/js/analytics.js).
 */
final class Analytics
{
    /** A GA4 measurement ID. */
    public const ID_PATTERN = '/^G-[A-Z0-9]{10}$/';

    /** Session key for events that must survive a redirect (a Livewire redirect leaves before the browser could report them). */
    private const FLASH_KEY = 'analytics';

    /** The only events GA ever hears about, and the parameters each may carry. */
    public const EVENTS = [
        'room_created' => ['difficulty', 'question_count', 'categories_count'],
        'subcategory_selected' => ['parent_category', 'subcategory'],
        'game_started' => ['player_count', 'question_count', 'difficulty', 'language'],
        'player_joined' => ['language'],
        'game_finished' => ['player_count', 'question_count', 'duration_seconds'],
        'play_again_clicked' => [],
        'language_switched' => ['to'],
        'sign_up' => [],
        'login' => [],
    ];

    /** The configured ID when it is well formed, otherwise null. */
    public static function id(): ?string
    {
        $id = config('lamma.analytics.id');

        return is_string($id) && preg_match(self::ID_PATTERN, $id) === 1 ? $id : null;
    }

    /** Should this page load analytics at all? */
    public static function enabled(?Request $request = null): bool
    {
        $request ??= request();

        return self::id() !== null && app()->isProduction() && ! $request->is('admin', 'admin/*');
    }

    /**
     * The page as GA should see it: the route pattern, parameters named, query string dropped. A join code, room code or reset token
     * (all route parameters or query values) never appears.
     */
    public static function path(?Request $request = null): string
    {
        $request ??= request();
        $uri = $request->route()?->uri() ?? $request->path();
        $uri = preg_replace('/\{(\w+)\??\}/', ':$1', $uri) ?? $uri;

        return '/'.trim($uri, '/');
    }

    /** A short readable name for the page: the route name, else the sanitized path. */
    public static function title(?Request $request = null): string
    {
        $request ??= request();

        return $request->route()?->getName() ?? self::path($request);
    }

    /**
     * An event, checked: the name must be on the list, only its own parameters stay, and each is a short scalar (no objects, no long text).
     *
     * @param  array<string, mixed>  $params
     * @return array{name: string, params: array<string, string|int|float|bool>}|null null when the event is not allowed
     */
    public static function event(string $name, array $params = []): ?array
    {
        if (! array_key_exists($name, self::EVENTS)) {
            return null;
        }

        $clean = [];
        foreach (self::EVENTS[$name] as $key) {
            $value = $params[$key] ?? null;
            if (is_bool($value) || is_int($value) || is_float($value) || (is_string($value) && $value !== '' && mb_strlen($value) <= 40)) {
                $clean[$key] = $value;
            }
        }

        return ['name' => $name, 'params' => $clean];
    }

    /**
     * Report an event after the next full page load (use this when the action redirects).
     *
     * @param  array<string, mixed>  $params
     */
    public static function flash(string $name, array $params = []): void
    {
        $event = self::event($name, $params);
        if ($event === null || ! self::enabled()) {
            return;
        }
        // Registering also logs in: that is one sign_up, not a sign_up and a login.
        if ($name === 'login' && in_array('sign_up', array_column((array) session(self::FLASH_KEY, []), 'name'), true)) {
            return;
        }

        session()->push(self::FLASH_KEY, $event);
    }

    /**
     * Report an event now, from a Livewire action that stays on the page. The browser's `analytics` listener passes it to gtag.
     *
     * @param  array<string, mixed>  $params
     */
    public static function dispatch(Component $component, string $name, array $params = []): void
    {
        $event = self::event($name, $params);
        if ($event !== null && self::enabled()) {
            $component->dispatch('analytics', name: $event['name'], params: $event['params']);
        }
    }

    /**
     * The events flashed before the redirect that led here, each once.
     *
     * @return list<array{name: string, params: array<string, string|int|float|bool>}>
     */
    public static function pull(): array
    {
        return array_values(session()->pull(self::FLASH_KEY, []));
    }
}
