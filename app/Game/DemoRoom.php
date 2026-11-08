<?php

namespace App\Game;

use App\Enums\HostScreenLocale;
use App\Models\Category;
use App\Models\Room;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The no-signup demo: a stranger starts a game on a laptop or TV without an account, scans the code with their phone, and plays against two bots.
 *
 * It is the one exception to "the host needs an account", and it is kept small: the room is an ordinary Classic room (same engine, same screens, same
 * sounds) with fixed settings, `is_demo` set, no host_id and the sha256 of the host cookie's token in `host_token_hash`. Only the browser that holds that
 * cookie opens /host/{code} (PlayerIdentity::hostFor, RoomPolicy, the `room.host` middleware). A demo cannot be played again, is limited per IP and per host cookie, and
 * `lamma:prune` deletes it after lamma.demo.lifetime_hours.
 */
class DemoRoom
{
    /** The categories every demo uses (top-level slugs; a category includes its subcategories). */
    public const CATEGORY_SLUGS = ['arab-world', 'general-knowledge'];

    public const QUESTIONS = 4;

    public const SECONDS = 15;

    public function __construct(
        private readonly RoomManager $rooms,
        private readonly Bots $bots,
        private readonly PlayerIdentity $identity,
    ) {}

    /** The fixed settings of a demo: 4 questions, 15 s, Mixed, both languages, double final on, no selfies. Classic mode. */
    public function settings(): RoomSettings
    {
        return new RoomSettings(
            categoryIds: array_values(Category::query()->whereIn('slug', self::CATEGORY_SLUGS)->whereNull('parent_id')->active()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all()),
            questionCount: self::QUESTIONS,
            secondsPerQuestion: self::SECONDS,
            difficulty: null,
            hostScreenLocale: HostScreenLocale::Both,
            showPhotos: false,
            doubleFinal: true,
        );
    }

    /** The rate-limiter key of an IP address's demos. */
    public static function ipKey(string $ip): string
    {
        return 'demo-ip:'.$ip;
    }

    /** The rate-limiter key of one demo-host cookie's demos (the token's hash: the token itself is never stored). */
    public static function cookieKey(string $token): string
    {
        return 'demo-cookie:'.hash('sha256', $token);
    }

    /**
     * A new demo room for the visitor at $ip, with its bots, and the host cookie queued on the response.
     *
     * Two limits, both per hour: lamma.demo.per_ip_per_hour for the address and lamma.demo.per_cookie_per_hour for the browser's demo-host cookie (when it has one: a browser
     * without the cookie is only counted by address, and the start gives it one). Only a start that succeeds counts, so a refusal does not stretch the wait.
     *
     * @throws DemoLimitReached when this address or this browser has started too many in the last hour (retryAfter: the longer wait of the two)
     * @throws NotEnoughQuestions when the demo's categories cannot fill a game
     */
    public function start(string $ip): Room
    {
        $limits = [self::ipKey($ip) => (int) config('lamma.demo.per_ip_per_hour')];
        if (($token = $this->identity->demoHostToken()) !== null) {
            $limits[self::cookieKey($token)] = (int) config('lamma.demo.per_cookie_per_hour');
        }

        $wait = 0;
        foreach ($limits as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $wait = max($wait, RateLimiter::availableIn($key));
            }
        }
        if ($wait > 0) {
            throw new DemoLimitReached($wait);
        }

        $token = $this->identity->issueDemoHostToken();
        $room = $this->rooms->create(null, $this->settings(), null, [
            'is_demo' => true,
            'host_token_hash' => hash('sha256', $token),
        ]);
        $this->bots->seed($room);

        RateLimiter::hit(self::ipKey($ip), 3600);
        RateLimiter::hit(self::cookieKey($token), 3600);

        return $room;
    }
}
