<?php

namespace App\Events;

use App\Game\RoomPresence;
use Carbon\CarbonInterface;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something happened in a room. Sent straight away (no queue worker needed) on presence-room.{code}, which only the room's
 * host and participants can join. Payloads carry only what the screens show: never a guest token, an email, or (before the
 * reveal) which option is correct. Every payload has the server clock (server_time, epoch ms) so a screen can correct the
 * drift between its own clock and the server's when it draws a countdown.
 */
abstract class RoomEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly string $roomCode) {}

    /** @return list<PresenceChannel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel(RoomPresence::channel($this->roomCode))];
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['code' => $this->roomCode, 'server_time' => now()->getTimestampMs(), ...$this->payload()];
    }

    /**
     * What this event adds to the code and the server time.
     *
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [];
    }

    /**
     * A translated attribute as {ar, en}: both languages, whatever else is stored.
     *
     * @return array{ar: string|null, en: string|null}
     */
    protected static function translations(Model $model, string $attribute): array
    {
        /** @phpstan-ignore method.notFound (HasTranslations) */
        $all = $model->getTranslations($attribute);

        return ['ar' => $all['ar'] ?? null, 'en' => $all['en'] ?? null];
    }

    /** UTC ISO-8601 with milliseconds, e.g. 2026-10-01T12:00:12.250Z. */
    protected static function iso(?CarbonInterface $moment): ?string
    {
        return $moment?->clone()->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
