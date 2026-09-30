<?php

use App\Enums\Difficulty;
use App\Enums\HostScreenLocale;
use App\Enums\RoomStatus;
use App\Game\RoomSettings;
use App\Models\PlayerAnswer;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\RoomQuestion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

it('gives a new room lobby status and default settings', function () {
    $room = Room::factory()->create();

    expect($room->fresh()->status)->toBe(RoomStatus::Lobby)
        ->and($room->fresh()->settings)->toEqual(new RoomSettings)
        ->and($room->settings->questionCount)->toBe(10)
        ->and($room->settings->secondsPerQuestion)->toBe(20)
        ->and($room->settings->difficulty)->toBeNull()
        ->and($room->settings->hostScreenLocale)->toBe(HostScreenLocale::Both);
});

it('round-trips room settings through JSON', function () {
    $settings = new RoomSettings([3, 5], 15, 30, Difficulty::Hard, HostScreenLocale::Ar);
    $room = Room::factory()->create(['settings' => $settings]);

    expect($room->fresh()->settings)->toEqual($settings)
        ->and(json_decode($room->getRawOriginal('settings'), true))->toBe([
            'category_ids' => [3, 5],
            'question_count' => 15,
            'seconds_per_question' => 30,
            'difficulty' => 'hard',
            'host_screen_locale' => 'ar',
        ]);
});

it('fills missing or invalid settings keys with defaults', function () {
    $settings = RoomSettings::fromArray(['question_count' => '7', 'difficulty' => 'nonsense']);

    expect($settings->questionCount)->toBe(7)
        ->and($settings->secondsPerQuestion)->toBe(20)
        ->and($settings->difficulty)->toBeNull();
});

it('stores answer and question timestamps with millisecond precision', function () {
    $roomQuestion = RoomQuestion::factory()->create();
    $answeredAt = Carbon::parse('2026-01-01 12:00:00.437');

    $answer = PlayerAnswer::factory()->create([
        'room_question_id' => $roomQuestion->id,
        'room_player_id' => RoomPlayer::factory()->create(['room_id' => $roomQuestion->room_id]),
        'answered_at' => $answeredAt,
    ]);

    expect($answer->fresh()->answered_at->format('Y-m-d H:i:s.v'))->toBe('2026-01-01 12:00:00.437');
});

describe('unique constraints', function () {
    it('allows one answer per player per question', function () {
        $answer = PlayerAnswer::factory()->create();

        PlayerAnswer::factory()->create([
            'room_question_id' => $answer->room_question_id,
            'room_player_id' => $answer->room_player_id,
        ]);
    })->throws(QueryException::class);

    it('allows one seat per user per room', function () {
        $room = Room::factory()->create();
        $user = User::factory()->create();

        RoomPlayer::factory()->forUser($user)->create(['room_id' => $room->id]);
        RoomPlayer::factory()->forUser($user)->create(['room_id' => $room->id]);
    })->throws(QueryException::class);

    it('allows one seat per guest token per room', function () {
        $room = Room::factory()->create();

        RoomPlayer::factory()->create(['room_id' => $room->id, 'guest_token' => 'abc']);
        RoomPlayer::factory()->create(['room_id' => $room->id, 'guest_token' => 'abc']);
    })->throws(QueryException::class);

    it('allows many guests (NULL user_id) in the same room', function () {
        $room = Room::factory()->create();

        RoomPlayer::factory()->count(3)->create(['room_id' => $room->id]);

        expect($room->players)->toHaveCount(3);
    });

    it('allows one question per position per room', function () {
        $room = Room::factory()->create();

        RoomQuestion::factory()->create(['room_id' => $room->id, 'position' => 1]);
        RoomQuestion::factory()->create(['room_id' => $room->id, 'position' => 1]);
    })->throws(QueryException::class);
});
