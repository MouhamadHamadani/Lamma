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
 * cookie opens /host/{code} (PlayerIdentity::hostFor, RoomPolicy, the `room.host` middleware). A demo cannot be played again, is limited per IP, and
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

    /**
     * A new demo room for the visitor at $ip, with its bots, and the host cookie queued on the response.
     *
     * @throws DemoLimitReached when this address has started too many in the last hour
     * @throws NotEnoughQuestions when the demo's categories cannot fill a game
     */
    public function start(string $ip): Room
    {
        $key = 'demo-rooms:'.$ip;
        if (RateLimiter::tooManyAttempts($key, (int) config('lamma.demo.rooms_per_ip_per_hour'))) {
            throw new DemoLimitReached(RateLimiter::availableIn($key));
        }

        $room = $this->rooms->create(null, $this->settings(), null, [
            'is_demo' => true,
            'host_token_hash' => hash('sha256', $this->identity->issueDemoHostToken()),
        ]);
        $this->bots->seed($room);

        RateLimiter::hit($key, 3600);

        return $room;
    }
}
