<?php

namespace App\Game;

use App\Models\Room;

/**
 * What the result share card shows (resources/js/share-card.js draws it in the browser, on a canvas, so the browser shapes the Arabic): the headline, the top
 * three players, the viewer's own place on a phone, the site address. It is a PUBLIC picture that people post, so this is the whole list of what may be in it:
 * nicknames and scores (already on the big screen), places, the address. No photo, no room code, no account, no token, no player id.
 */
final class ShareCard
{
    public function __construct(private readonly Podium $podium) {}

    /**
     * @param  list<array{rank: int, player: array{id: int, nickname: string, locale: string, avatar_url?: string|null}, total: int, gained: int}>  $ranking
     * @param  'host'|'phone'  $surface
     * @param  int|null  $myPlayerId  the viewer's row on a phone (their place is highlighted), null on the big screen
     * @return array<string, mixed>
     */
    public function for(Room $room, array $ranking, string $surface, ?int $myPlayerId = null): array
    {
        $winners = $this->podium->winners($ranking);
        $names = array_map(fn (array $winner) => self::isolate($winner['nickname']), $winners);

        $headline = match (count($winners)) {
            0 => __('No winner this time'),
            1 => __(':name wins!', ['name' => $names[0]]),
            2 => __(':names win!', ['names' => implode(' & ', $names)]),
            default => __("It's a tie!"),
        };

        $mine = $myPlayerId === null ? null : collect($ranking)->firstWhere('player.id', $myPlayerId);

        return [
            'locale' => app()->getLocale(),
            'surface' => $surface,
            'brand' => app()->getLocale() === 'ar' ? 'لمّة' : 'Lamma',
            'logo' => '/brand/lamma-logo-stacked-dark.svg',
            'eyebrow' => __('Game over · :count questions', ['count' => $room->roomQuestions()->count()]),
            'headline' => $headline,
            'top' => array_map(
                fn (array $row) => ['rank' => $row['rank'], 'name' => $row['player']['nickname'], 'score' => $row['total']],
                array_slice($ranking, 0, 3),
            ),
            'me' => $mine === null ? null : ['label' => __('Your place: :rank', ['rank' => Scoreboard::ordinal($mine['rank'])])],
            'cta' => __('The quiz night for everyone'),
            'host' => self::host(),
            'filename' => 'lamma-result.png',
            'shareTitle' => __('My result on Lamma'),
            'shareText' => self::host(),
        ];
    }

    /** The site's address without the scheme, from the app's own URL (config/app.php), never hard-coded: the production domain on the live site, localhost when developing. */
    public static function host(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : (string) config('app.url');
    }

    /** A name stays in one piece inside a right-to-left sentence (a left-to-right isolate), so the punctuation around it does not jump. */
    private static function isolate(string $name): string
    {
        return "\u{2066}{$name}\u{2069}";
    }
}
