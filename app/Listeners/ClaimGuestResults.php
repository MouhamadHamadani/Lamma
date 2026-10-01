<?php

namespace App\Listeners;

use App\Game\PlayerIdentity;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;

/**
 * A guest's score is not saved to any profile. When someone logs in or registers on the device that played as a guest,
 * the guest rows (same lamma_guest token, still unclaimed, created inside the claim window) become theirs, which is
 * what makes them "saved": only rows with a user_id show in a user's history and stats.
 */
class ClaimGuestResults
{
    public function __construct(private readonly PlayerIdentity $identity) {}

    public function handle(Login|Registered $event): void
    {
        $user = $event->user;
        $token = $this->identity->guestToken();

        if ($token === null || ! $user instanceof User) {
            return;
        }

        DB::transaction(function () use ($user, $token) {
            $guestRows = RoomPlayer::query()
                ->whereNull('user_id')
                ->where('guest_token', $token)
                ->where('created_at', '>=', now()->subHours((int) config('lamma.guest_claim_hours')))
                ->lockForUpdate()
                ->get();

            foreach ($guestRows as $row) {
                // One row per user per room (unique index): if they already played this room as themselves, leave the guest row alone.
                if (RoomPlayer::where('room_id', $row->room_id)->where('user_id', $user->id)->exists()) {
                    continue;
                }

                $row->update(['user_id' => $user->id]);
            }
        });
    }
}
