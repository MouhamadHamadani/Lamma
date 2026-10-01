<?php

return [

    // Encrypted, httpOnly cookie holding a guest's random token (see App\Game\PlayerIdentity).
    'guest_cookie' => 'lamma_guest',
    'guest_cookie_days' => 30,

    // A guest's results can be attached to an account that logs in or registers on the same device
    // within this many hours of the guest joining a room (see App\Listeners\ClaimGuestResults).
    'guest_claim_hours' => (int) env('LAMMA_GUEST_CLAIM_HOURS', 24),

    // Join attempts per IP per minute (stops room-code guessing).
    'join_attempts_per_minute' => 10,

];
