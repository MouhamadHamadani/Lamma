<?php

use App\Enums\HostScreenLocale;
use App\Enums\RoomStatus;
use App\Events\GameStarted;
use App\Events\PlayerJoined;
use App\Events\PlayerUpdated;
use App\Events\QuestionStarted;
use App\Game\GameEngine;
use App\Game\JoinException;
use App\Game\NicknameFilter;
use App\Game\PlayerIdentity;
use App\Game\PlayerPhoto;
use App\Game\RoomJoiner;
use App\Game\RoomManager;
use App\Game\RoomRoster;
use App\Game\Scoreboard;
use App\Livewire\Host\HostLobby;
use App\Livewire\Player\JoinRoom;
use App\Livewire\Player\PlayerLobby;
use App\Models\Room;
use App\Models\RoomPlayer;
use Faker\Provider\en_US\Person;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
 * The nickname filter: Arabic, English and Arabizi words (resources/blocklist/nicknames.php), compared after a clean-up that undoes the usual disguises, and a
 * split into 'contains' and 'exact' words that keeps Scunthorpe playable. The message never says which word.
 */

const NICK_TOKEN = 'n5n5n5n5n5n5n5n5n5n5n5n5n5n5n5n5n5n5n5n5n5n5n5n5n5n5n5n5n5n5n5n5';

function nicknameFilter(): NicknameFilter
{
    return new NicknameFilter;
}

describe('what it blocks', function () {
    it('blocks listed words, whatever the letter case', function (string $nickname) {
        expect(nicknameFilter()->isBlocked($nickname))->toBeTrue();
    })->with([
        'english' => 'fuck', 'english upper' => 'FUCK', 'english mixed' => 'FuCk', 'english, inside a name' => 'xXfuckerXx',
        'a slur' => 'nigger', 'an insult' => 'Bitch', 'a short word that is the whole name' => 'Ass', 'another' => 'cunt',
        'arabic' => 'شرموطة', 'arabic, inside a name' => 'ابن الشرموطة', 'arabic exact' => 'عرص', 'arabic vulgar' => 'منيوك',
        'arabizi' => 'sharmouta', 'arabizi, another spelling' => 'Sharmoota', 'arabizi exact' => 'ayri', 'arabizi with a digit' => '3ars',
    ]);

    it('undoes the usual disguises', function (string $nickname) {
        expect(nicknameFilter()->isBlocked($nickname))->toBeTrue();
    })->with([
        'spaced' => 'F u c k', 'dotted' => 'f.u.c.k', 'dashed and underscored' => 'f-u_c-k', 'stretched' => 'fuuuuuck', 'stretched at the end' => 'fuckkkk', 'stretched short word' => 'assss',
        'accents' => 'Fück', 'a digit for a letter' => 'sh1t', 'a symbol for a letter' => 'b!tch', 'a dollar sign' => '$hit', 'an at sign' => 'f@ggot', 'leet speak' => 'p0rn0',
        'two words, one bad' => 'Big Dick', 'with underscores and digits' => 'big_dick_69', 'camel case' => 'BigDick', 'a number after it' => 'dick69', 'a number before it' => '69dick',
        'letters split by spaces' => 'c u n t', 'punctuation inside' => 'ass!!!',
        'arabic spaced' => 'ع ر ص', 'arabic with tatweel' => 'عـــرص', 'arabic with diacritics' => 'عَرْص', 'arabic stretched' => 'شرمووووطة', 'arabic all marks' => 'شَرمُوطَة',
        'arabic with hamza variants' => 'كُسّ أُمّك', 'arabic ta marbuta as ha' => 'قحبه', 'arabic with ta marbuta' => 'قحبة', 'arabic alef variants' => 'إبن الشرموطة',
        'eastern digits around it' => '٦٩عرص', 'arabizi spaced' => 'kos omak', 'arabizi stretched' => 'sharmoooota', 'arabizi with a digit, spaced' => '3 ars', 'arabizi upper' => '3ARS',
        'zero width characters' => "f\u{200B}u\u{200B}c\u{200B}k", 'full width letters' => 'ｆｕｃｋ', 'a direction mark' => "\u{200F}عرص",
    ]);

    it('says nothing about which word matched: it only answers yes or no', function () {
        expect(nicknameFilter()->isBlocked('Big Dick'))->toBeTrue()->and((new ReflectionMethod(NicknameFilter::class, 'isBlocked'))->getReturnType()->getName())->toBe('bool');
    });
});

describe('what it leaves alone (the Scunthorpe problem)', function () {
    it('lets innocent names that merely contain a short bad word through', function (string $nickname) {
        expect(nicknameFilter()->isBlocked($nickname))->toBeFalse();
    })->with([
        'Scunthorpe', 'Cassandra', 'Class', 'Assad', 'Essex', 'Sussex',
        'Dickinson', 'Hancock', 'Peacock', 'Cocktail', 'Analysis', 'Analytics',
        'Titan', 'Cumberland', 'Bassist', 'Penistone', 'Therapist', 'Grapes',
        'Torpedo', 'Nazir', 'Nazim', 'Kostas', 'Sexton', 'Peele',
        'Shiite', 'كساب', 'كسّار', 'نيكول', 'نيكولا', 'خولة',
        'زبيدة', 'كسرى', 'شاذلي', 'زينب', 'عمر', 'محمد',
        'ليلى', 'سارة', 'كريم', 'علي', 'سلمى', 'نور',
        'حسن', 'Sara', 'Karim', 'Omar', 'Maya', 'Hadi 7',
        'Layla 🎉', 'Zo', 'Ears', 'As', 'Asa',
    ]);

    it('leaves every first name Faker knows alone, with a number after it, the way the test factory makes them', function () {
        $names = [];
        foreach (['firstNameMale', 'firstNameFemale'] as $property) {
            $names = [...$names, ...(new ReflectionProperty(Person::class, $property))->getValue()];
        }
        expect(count($names))->toBeGreaterThan(100);

        $blocked = array_values(array_filter($names, fn (string $name) => nicknameFilter()->isBlocked($name.'42') || nicknameFilter()->isBlocked($name)));

        expect($blocked)->toBe([]);
    });

    it('lets an empty or symbols-only nickname through to the length rules instead of failing on it', function () {
        expect(nicknameFilter()->isBlocked(''))->toBeFalse()->and(nicknameFilter()->isBlocked('!!!'))->toBeFalse()->and(nicknameFilter()->isBlocked('1234'))->toBeFalse();
    });
});

describe('the list', function () {
    it('is read from config/lamma.php, which loads the separate file', function () {
        expect(config('lamma.blocked_words'))->toBe(require resource_path('blocklist/nicknames.php'))->and(file_get_contents(resource_path('blocklist/nicknames.php')))->toContain('To add a word');
    });

    it('blocks every one of its own words, and every word has enough letters to be safe in "contains"', function () {
        $lists = config('lamma.blocked_words');
        $filter = nicknameFilter();

        foreach (['contains', 'exact'] as $kind) {
            foreach ($lists[$kind] as $word) {
                expect($filter->isBlocked($word))->toBeTrue("{$kind}: {$word} does not block itself");
            }
        }
        foreach ($lists['contains'] as $word) {
            $letters = NicknameFilter::fold($word, preg_match('/\d/', $word) ? 'arabizi' : 'latin');
            $arabic = preg_match('/\p{Arabic}/u', $word) === 1;
            expect(mb_strlen($letters))->toBeGreaterThanOrEqual($arabic ? 3 : 4, "contains: {$word} is too short to be safe inside a name");
        }
    });

    it('has Arabic, English and Arabizi in both lists', function () {
        foreach (['contains', 'exact'] as $kind) {
            $words = implode(' ', config("lamma.blocked_words.{$kind}"));

            expect(preg_match('/\p{Arabic}/u', $words))->toBe(1)->and(preg_match('/[a-z]{3}/', $words))->toBe(1)->and(count(config("lamma.blocked_words.{$kind}")))->toBeGreaterThan(20);
        }
    });

    it('can be extended with one line: a new word is blocked, with its disguises', function () {
        config(['lamma.blocked_words.contains' => [...config('lamma.blocked_words.contains'), 'badwordxyz'], 'lamma.blocked_words.exact' => [...config('lamma.blocked_words.exact'), 'shorty']]);
        $filter = new NicknameFilter;

        expect($filter->isBlocked('Bad Word XYZ'))->toBeTrue()->and($filter->isBlocked('my_b4dw0rdxyz'))->toBeTrue()->and($filter->isBlocked('Shorty'))->toBeTrue()
            ->and($filter->isBlocked('Shortyland'))->toBeFalse();
    });

    it('does nothing with an empty list', function () {
        config(['lamma.blocked_words' => []]);

        expect((new NicknameFilter)->isBlocked('fuck'))->toBeFalse();
    });

    it('folds a text the way it folds its own words', function () {
        expect(NicknameFilter::fold('Fück! 3'))->toBe('fuckie')->and(NicknameFilter::fold('أُمّ ٱلكتاب'))->toBe('امالكتاب')->and(NicknameFilter::fold('  ', 'arabizi'))->toBe('');
    });
});

describe('the gaps Phase 14.1 closed', function () {
    it('blocks the Arabizi words that were missing, with their disguises', function (string $nickname) {
        expect(nicknameFilter()->isBlocked($nickname))->toBeTrue();
    })->with([
        'zeb' => 'zeb', 'zob' => 'Zob', 'zeb stretched' => 'zeeeb', 'zob with a number' => 'zob69', 'zeb in a name' => 'big_zeb',
        'teez' => 'teez', 'teez stretched' => 'teeeez', 'tiz' => 'TIZ', 'tez' => 'tez', 'tiz spaced' => 't i z',
        'khawal' => 'khawal', 'khawal in a name' => 'xXkhawalXx', 'khawal as 5awal' => '5awal', 'khawal short' => '5wal', 'khawal short, stretched' => '5waaal',
        'nayek' => 'nayek', 'nayek upper' => 'NAYEK', 'nayyek' => 'nayyek', 'neek' => 'neek', 'neek stretched' => 'neeeek', 'nayek in a name' => 'abu_nayek',
        'dayous' => 'dayous', 'dayyous' => 'dayyous', 'dayus' => 'dayus', 'dayyous in a name' => 'ya_dayyous',
        'louti' => 'louti', 'lawati' => 'lawati', 'lawat' => 'lawat', 'lawati upper' => 'LAWATI',
        'metnayek' => 'metnayek', 'mitnayek' => 'mitnayek', 'metnayek in a name' => 'ibn_metnayek',
        '3ers' => '3ers', '3ers upper' => '3ERS', '3erse' => '3erse', '3ers spaced' => '3 e r s', 'ersh' => 'ersh', 'ersh stretched' => 'erssshh',
        '3ars with other vowels' => '3irs', '3ors' => '3ors', '3urs' => '3urs', '3arse' => '3arse', '3arsh' => '3arsh', '3ersh' => '3ersh',
    ]);

    it('reads v as u, but only as a disguise: FvCk and Cvnt are caught', function (string $nickname) {
        expect(nicknameFilter()->isBlocked($nickname))->toBeTrue();
    })->with(['FvCk' => 'FvCk', 'fvck' => 'fvck', 'fvvvck' => 'fvvvck', 'Cvnt' => 'Cvnt', 'Bvllshit' => 'Bvllshit', 'with a symbol too' => 'f.v.c.k', 'a word with a real v' => 'Vagina']);

    it('keeps v out of the Arabizi reading, and out of innocent names', function () {
        expect(NicknameFilter::fold('FvCk'))->toBe('fuck')->and(NicknameFilter::fold('Vera', 'arabizi'))->toBe('vera')
            ->and(nicknameFilter()->isBlocked('Vera'))->toBeFalse()->and(nicknameFilter()->isBlocked('Ivan'))->toBeFalse()->and(nicknameFilter()->isBlocked('Slava'))->toBeFalse()
            ->and(nicknameFilter()->isBlocked('Victor'))->toBeFalse()->and(nicknameFilter()->isBlocked('Evelyn'))->toBeFalse()->and(nicknameFilter()->isBlocked('Calvin'))->toBeFalse();
    });

    it('keeps the short and ambiguous ones to whole words: a longer name that merely contains them is fine', function (string $nickname) {
        expect(nicknameFilter()->isBlocked($nickname))->toBeFalse();
    })->with([
        'Zeba', 'Zebib', 'Zebedee', 'Tizi Ouzou', 'Tiziana', 'Tezcan', 'Neeko', 'Neekon', 'Nayeka', 'Nayef', 'Dayana', 'Dayan', 'Louay', 'Loutfi', 'Lotfi', 'Lawand', 'Lawaty',
        'Khawla', 'Khawlani', 'Ershad', 'Ersan', 'Ears', 'Zebra', 'Zobeida', 'Metn', 'Mitri', 'Metnaye',
    ]);

    it('has an empty, documented section for sectarian and political slurs, waiting for a native speaker (nothing was invented)', function () {
        $file = file_get_contents(resource_path('blocklist/nicknames.php'));

        expect($file)->toContain('SECTARIAN AND POLITICAL SLURS')->toContain('EMPTY ON PURPOSE')->toContain('native speaker')
            ->and(substr_count($file, 'SECTARIAN AND POLITICAL SLURS: empty on purpose'))->toBe(2);   // one in each list, a comment and no words
    });

    it('still blocks نيك: it is a rude word and also the name Nick written in Arabic, and the word wins (accepted)', function () {
        expect(nicknameFilter()->isBlocked('نيك'))->toBeTrue()->and(nicknameFilter()->isBlocked('نيكول'))->toBeFalse()->and(nicknameFilter()->isBlocked('Nick'))->toBeFalse();
    });
});

describe('the innocent names (tests/Fixtures/innocent-names.php)', function () {
    $fixture = fn () => require base_path('tests/Fixtures/innocent-names.php');

    it('is a list of at least 150 different names in both scripts', function () use ($fixture) {
        $names = [...$fixture()['latin'], ...$fixture()['arabic']];

        expect(count($names))->toBeGreaterThanOrEqual(150)->and(count(array_unique($names)))->toBe(count($names))
            ->and(count($fixture()['latin']))->toBeGreaterThan(60)->and(count($fixture()['arabic']))->toBeGreaterThan(60);
    });

    it('lets every one of them through, as they are, in capitals, with a number and with a space and a number', function () use ($fixture) {
        $filter = nicknameFilter();
        $blocked = [];

        foreach ([...$fixture()['latin'], ...$fixture()['arabic']] as $name) {
            foreach ([$name, mb_strtoupper($name), $name.'42', $name.' 7', '7'.$name] as $variant) {
                if ($filter->isBlocked($variant)) {
                    $blocked[] = $variant;
                }
            }
        }

        expect($blocked)->toBe([]);
    });
});

describe('joining', function () {
    beforeEach(function () {
        Queue::fake();
        Event::fake([PlayerJoined::class, PlayerUpdated::class]);
    });

    it('refuses a blocked nickname with one generic message under the nickname field, the same style as "taken"', function () {
        $room = gameRoom(players: 0);

        $page = Livewire::withCookie('lamma_guest', NICK_TOKEN)->test(JoinRoom::class)->set('code', $room->code)->set('nickname', 'Big Dick')->set('language', 'en')->call('join');

        $page->assertHasErrors(['nickname'])->assertSee('Please choose another nickname.')->assertSee('data-test="nickname-error"', false)->assertNoRedirect();
        expect($room->players()->count())->toBe(0)->and($page->errors()->get('nickname'))->toBe(['Please choose another nickname.']);

        // The same inline element shows "taken".
        RoomPlayer::factory()->for($room)->create(['nickname' => 'Hadi']);
        $taken = Livewire::withCookie('lamma_guest', NICK_TOKEN)->test(JoinRoom::class)->set('code', $room->code)->set('nickname', 'hadi')->set('language', 'en')->call('join');
        $taken->assertSee('data-test="nickname-error"', false)->assertSee('That nickname is taken in this room');
    });

    it('says it in Arabic on an Arabic page, and refuses an Arabic or Arabizi word too', function (string $nickname) {
        $room = gameRoom(players: 0);
        app()->setLocale('ar');

        Livewire::withCookie('lamma_guest', NICK_TOKEN)->test(JoinRoom::class)->set('code', $room->code)->set('nickname', $nickname)->set('language', 'ar')->call('join')
            ->assertSee('يرجى اختيار اسم مستعار آخر.');
        expect($room->players()->count())->toBe(0);
    })->with(['شرموطة', 'sharmouta', 'ع ر ص']);

    it('lets a good nickname in, and lets a returning player back in whatever the list says today', function () {
        $room = gameRoom(players: 0);

        Livewire::withCookie('lamma_guest', NICK_TOKEN)->test(JoinRoom::class)->set('code', $room->code)->set('nickname', 'Cassandra')->set('language', 'en')->call('join')->assertHasNoErrors();
        expect($room->players()->where('nickname', 'Cassandra')->count())->toBe(1);

        // The list grows: the player who is already in comes back to their row, they are not asked for a new name.
        config(['lamma.blocked_words.exact' => [...config('lamma.blocked_words.exact'), 'cassandra']]);
        app()->forgetInstance(NicknameFilter::class);
        $again = Livewire::withCookie('lamma_guest', NICK_TOKEN)->test(JoinRoom::class)->set('code', $room->code)->set('nickname', 'Whatever')->set('language', 'en')->call('join');
        $again->assertHasNoErrors();
        expect($room->players()->count())->toBe(1);
    });

    it('throws a JoinException for the nickname field from the rules themselves', function () {
        $room = gameRoom(players: 0);
        $request = Request::create('/join', 'POST', cookies: ['lamma_guest' => NICK_TOKEN]);
        $joiner = new RoomJoiner(new PlayerIdentity($request), app(PlayerPhoto::class));

        try {
            $joiner->join($room->code, 'f u c k', 'en');
            $this->fail('Expected the join to be refused');
        } catch (JoinException $e) {
            expect($e->field)->toBe('nickname')->and($e->getMessage())->toBe('Please choose another nickname.');
        }
        expect($room->players()->count())->toBe(0);
    });

    it('checks the filter before the "taken" rule, so a blocked word is never confirmed as taken', function () {
        $room = gameRoom(players: 0);
        RoomPlayer::factory()->for($room)->create(['nickname' => 'Fuck']);

        expect(fn () => app(RoomJoiner::class)->join($room->code, 'FUCK', 'en'))->toThrow(JoinException::class, 'Please choose another nickname.');
    });
});

describe('renaming in the lobby', function () {
    beforeEach(function () {
        Queue::fake();
        Event::fake([PlayerJoined::class, PlayerUpdated::class]);
    });

    it('refuses a blocked new name and keeps the old one', function () {
        $room = gameRoom(players: 0);
        $player = RoomPlayer::factory()->for($room)->create(['nickname' => 'Hadi', 'guest_token' => NICK_TOKEN, 'left_at' => null]);

        expect(fn () => app(RoomRoster::class)->rename($player, 'B1tch'))->toThrow(JoinException::class, 'Please choose another nickname.');
        expect($player->fresh()->nickname)->toBe('Hadi');
        Event::assertNotDispatched(PlayerUpdated::class);
    });

    it('shows the message on the phone lobby, in the same place as "taken"', function () {
        $room = gameRoom(players: 0);
        RoomPlayer::factory()->for($room)->create(['nickname' => 'Hadi', 'guest_token' => NICK_TOKEN, 'locale' => 'en', 'left_at' => null]);

        $page = Livewire::withCookie('lamma_guest', NICK_TOKEN)->test(PlayerLobby::class, ['room' => $room])->set('nickname', 'Big Dick')->call('saveName');

        $page->assertSet('nameError', 'Please choose another nickname.');
        expect($room->players()->first()->nickname)->toBe('Hadi');
    });

    it('lets a good rename through', function () {
        $room = gameRoom(players: 0);
        $player = RoomPlayer::factory()->for($room)->create(['nickname' => 'Hadi', 'left_at' => null]);

        expect(app(RoomRoster::class)->rename($player, 'Hadi Jr'))->toBeTrue()->and($player->fresh()->nickname)->toBe('Hadi Jr');
    });
});

describe('what the screens show', function () {
    beforeEach(function () {
        Queue::fake();
        Event::fake([PlayerJoined::class, PlayerUpdated::class, GameStarted::class, QuestionStarted::class]);
    });

    /** A player whose stored name the filter would refuse today (the list grew after they joined). */
    function blockedPlayer(Room $room, string $name = 'Fuckface', int $score = 0): RoomPlayer
    {
        return RoomPlayer::factory()->for($room)->create(['nickname' => $name, 'score' => $score, 'is_ready' => true, 'left_at' => null]);
    }

    it('shows "Player" for a blocked name on the host lobby, whatever is stored', function () {
        $room = gameRoom(players: 1);
        $bad = blockedPlayer($room);

        $html = Livewire::actingAs($room->host)->test(HostLobby::class, ['room' => $room])->html();

        expect($html)->not->toContain('Fuckface')->and($html)->toContain('Player')->and($bad->fresh()->nickname)->toBe('Player')->and($bad->getRawOriginal('nickname'))->toBe('Fuckface');
    });

    it('shows it on the scoreboard, the podium and every broadcast, never the word', function () {
        $room = gameRoom(players: 0);
        $bad = blockedPlayer($room, score: 500);
        $good = RoomPlayer::factory()->for($room)->create(['nickname' => 'Sara', 'score' => 300, 'is_ready' => true, 'left_at' => null]);

        $ranking = app(Scoreboard::class)->ranking($room);

        expect(collect($ranking)->pluck('player.nickname')->all())->toBe(['Player', 'Sara'])->and($bad->toBroadcast()['nickname'])->toBe('Player')->and($good->toBroadcast()['nickname'])->toBe('Sara')
            ->and(json_encode($ranking))->not->toContain('Fuckface');
    });

    it('keeps the stored name for everything that must stay unique: a rematch carries it over without a clash, and the admin sees it', function () {
        $room = gameRoom(players: 0);
        blockedPlayer($room, 'Fuckface');
        blockedPlayer($room, 'Fuckwit');
        $room->update(['status' => RoomStatus::Finished, 'finished_at' => now()]);

        $new = app(RoomManager::class)->playAgain($room, $room->host);

        expect($new->players()->count())->toBe(2)->and($new->players()->pluck('nickname')->all())->toBe(['Player', 'Player'])
            ->and(RoomPlayer::where('room_id', $new->id)->pluck('nickname')->all())->toBe(['Player', 'Player']);
        $raw = DB::table('room_players')->where('room_id', $new->id)->orderBy('id')->pluck('nickname')->all();
        expect($raw)->toBe(['Fuckface', 'Fuckwit']);
    });

    it('does not change a good name', function () {
        $room = gameRoom(players: 0);
        $player = RoomPlayer::factory()->for($room)->create(['nickname' => 'Scunthorpe']);

        expect($player->nickname)->toBe('Scunthorpe');
    });

    it('says "لاعب" on an Arabic host screen', function () {
        $room = gameRoom(players: 0, settings: ['hostScreenLocale' => HostScreenLocale::Ar]);
        blockedPlayer($room);

        $html = Livewire::actingAs($room->host)->test(HostLobby::class, ['room' => $room])->html();

        expect($html)->toContain('لاعب')->and($html)->not->toContain('Fuckface');
    });

    it('does not run a game any differently: a blocked name still plays, scores and ranks', function () {
        $room = gameRoom(players: 0, questions: 1);
        $bad = blockedPlayer($room);
        startGame($room);
        answer($bad, $room->fresh()->currentQuestion());
        app(GameEngine::class)->reveal($room->fresh(), 1, force: true);

        expect($bad->fresh()->score)->toBeGreaterThan(0);
    });
});
