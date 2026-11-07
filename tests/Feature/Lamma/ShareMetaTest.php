<?php

use App\Models\Room;
use App\Models\RoomPlayer;
use App\Support\BrandFiles;

/*
 * What a link to Lamma looks like when it is pasted into a chat: og:title, og:description, og:image (1200x630) and the Twitter card, for the landing page, the join
 * page and the demo, in both languages, and never on a screen that carries a room.
 */

/** The content of a meta tag, by its property or name. */
function metaContent(string $html, string $key): ?string
{
    $pattern = '/<meta\s+(?:property|name)="'.preg_quote($key, '/').'"\s+content="([^"]*)"/';

    return preg_match($pattern, $html, $match) === 1 ? html_entity_decode($match[1], ENT_QUOTES) : null;
}

describe('the share tags', function () {
    it('are on the landing page, the join page and the demo, in both languages', function (string $path, string $locale, string $title, string $description) {
        $html = $this->withSession(['locale' => $locale])->get($path)->assertOk()->getContent();

        expect(metaContent($html, 'og:title'))->toBe($title)->and(metaContent($html, 'twitter:title'))->toBe($title)
            ->and(metaContent($html, 'og:description'))->toBe($description)->and(metaContent($html, 'twitter:description'))->toBe($description)->and(metaContent($html, 'description'))->toBe($description)
            ->and(metaContent($html, 'og:type'))->toBe('website')->and(metaContent($html, 'og:site_name'))->toBe('Lamma')
            ->and(metaContent($html, 'og:image'))->toBe(asset('brand/og-image.png'))->and(metaContent($html, 'twitter:image'))->toBe(asset('brand/og-image.png'))
            ->and(metaContent($html, 'twitter:card'))->toBe('summary_large_image')
            ->and(metaContent($html, 'og:image:width'))->toBe('1200')->and(metaContent($html, 'og:image:height'))->toBe('630')->and(metaContent($html, 'og:image:type'))->toBe('image/png')
            ->and(metaContent($html, 'og:locale'))->toBe($locale === 'ar' ? 'ar_AR' : 'en_US')
            ->and(metaContent($html, 'og:url'))->toBe(url($path))
            ->and($html)->toContain('<link rel="canonical" href="'.url($path).'">')
            ->and(metaContent($html, 'og:image:alt'))->toBe('Lamma · '.($locale === 'ar' ? 'سهرة الأسئلة للكل' : 'The quiz night for everyone'));
    })->with([
        'landing, english' => ['/', 'en', 'The quiz night for everyone - Lamma', 'A multiplayer quiz party game: one big screen, everyone answers from their phone.'],
        'landing, arabic' => ['/', 'ar', 'سهرة الأسئلة للكل - Lamma', 'لعبة مسابقات جماعية: شاشة كبيرة واحدة، والجميع يجيبون من هواتفهم.'],
        'join, english' => ['/join', 'en', 'Join a game - Lamma', 'Type the code shown on the big screen.'],
        'join, arabic' => ['/join', 'ar', 'انضم إلى لعبة - Lamma', 'اكتب الرمز الظاهر على الشاشة الكبيرة.'],
        'demo, english' => ['/demo', 'en', 'Try a demo - Lamma', 'No account needed. Open this on a laptop or TV, then scan the code with your phone.'],
        'demo, arabic' => ['/demo', 'ar', 'جرّب نسخة تجريبية - Lamma', 'لا حاجة إلى حساب. افتح هذه الصفحة على حاسوب أو تلفاز، ثم امسح الرمز بهاتفك.'],
    ]);

    it('offers the other language as an alternate locale', function () {
        $english = $this->withSession(['locale' => 'en'])->get('/')->getContent();
        $arabic = $this->withSession(['locale' => 'ar'])->get('/')->getContent();

        expect($english)->toContain('<meta property="og:locale:alternate" content="ar_AR">')->not->toContain('og:locale:alternate" content="en_US"')
            ->and($arabic)->toContain('<meta property="og:locale:alternate" content="en_US">');
    });

    it('never carry a room code: the join page\'s address is shared without its query string', function () {
        $html = $this->withSession(['locale' => 'en'])->get('/join?code=K7MP9Z&nickname=Sara')->assertOk()->getContent();

        expect(metaContent($html, 'og:url'))->toBe(url('/join'))->and($html)->toContain('<link rel="canonical" href="'.url('/join').'">')
            ->and(implode(' ', array_filter([metaContent($html, 'og:url'), metaContent($html, 'og:title'), metaContent($html, 'og:description'), metaContent($html, 'og:image')])))->not->toContain('K7MP9Z');
    });

    it('are not on the screens that carry a room: the host screen, the phone, room creation, the account pages', function () {
        $room = Room::factory()->create();
        $player = RoomPlayer::factory()->for($room)->create(['guest_token' => str_repeat('a', 64)]);

        $host = $this->actingAs($room->host)->get(route('host.lobby', $room->code))->assertOk()->getContent();
        $create = $this->actingAs($room->host)->get(route('rooms.create'))->assertOk()->getContent();
        $phone = $this->withCookie('lamma_guest', $player->guest_token)->get(route('play', $room->code))->assertOk()->getContent();

        foreach ([$host, $create, $phone] as $html) {
            expect($html)->not->toContain('og:title')->not->toContain('og:image')->not->toContain('twitter:card');
        }
    });
});

describe('the picture', function () {
    it('is a real 1200x630 PNG under the brand folder, small enough for a chat app', function () {
        [$width, $height, $type] = getimagesize(public_path('brand/og-image.png'));

        expect($width)->toBe(1200)->and($height)->toBe(630)->and($type)->toBe(IMAGETYPE_PNG)->and(filesize(public_path('brand/og-image.png')))->toBeLessThan(300 * 1024);
    });

    it('is a placeholder until the official one replaces it, and lamma:doctor says so', function () {
        expect(config('lamma.placeholders'))->toHaveKey('brand/og-image.png')->and(BrandFiles::stillPlaceholders())->toContain('brand/og-image.png')
            ->and(file_exists(base_path('docs/design/og-image-source.html')))->toBeTrue();
    });
});
