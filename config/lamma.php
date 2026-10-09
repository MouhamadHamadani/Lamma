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

    // `php artisan lamma:prune` (scheduled daily) leaves a timestamp here every time it runs, and `lamma:doctor` fails when it is older than
    // scheduler_max_hours: the sign that the `schedule:run` cron line is missing.
    'heartbeat_key' => 'lamma.scheduler.last_run',
    'scheduler_max_hours' => 26,

    // Brand and sound files that are still PLACEHOLDERS: path under public/ => sha256 of the placeholder (SVG with LF line endings, see
    // App\Support\BrandFiles). The official files replace them under the same names; until they do, `lamma:doctor` warns. When you
    // replace one, you can delete its line here.
    'placeholders' => [
        'brand/lamma-app-icon.png' => '8d7fa0d495f1511286a39828a461fdfc308ff6c887f3e4b905e7515e7d446fa1',
        'brand/lamma-app-icon.svg' => '8b5a625560006a04ab4f77021d7fe3d50210447c222f26265056ea2032114d31',
        'brand/lamma-icon.svg' => 'c0016cc8e5dd122c73cfc2578bc0e1077008eac4c935e29eb9dbfd987636247a',
        'brand/lamma-logo-horizontal-dark.svg' => '9cfcddd3e5f7f37efd5ad0a58b60ea26ae3148a779dd253d66a3fef7b6e14824',
        'brand/lamma-logo-horizontal.svg' => 'f95b8b8aa9eb7bce53d504c10cb586a4a3ad91b2b9b5eaee68e26a28f8e586e2',
        'brand/lamma-logo-stacked-dark.svg' => '8cf3e19fc29dce9e9de4db9b205916220eb7f77f9db6bf1571fd23aaa7439477',
        'brand/lamma-logo-stacked.svg' => '99e1a6c204d31fe3c420d8dcb045013d9b52d83f1a291f9fa7d498e14b7c9879',
        'favicon.svg' => '8b5a625560006a04ab4f77021d7fe3d50210447c222f26265056ea2032114d31',
        'favicon.ico' => '30148cc794b27536f089054040818effa8edea70398a7948e0cd2ac417d4198d',
        'apple-touch-icon.png' => '7aed51dda041bd179d82175aee381615aac7030d3b58ef2c246ab46702e2b303',
        'sounds/podium.wav' => '96464c8cf985179238cb481aa7285ac8c01d5263a6921f2bc0700f3793a9afff',
        'sounds/question-start.wav' => 'abdd1dad5ccafed9c22bacabd65e4c9088f5404dd5cf12e43e812f2cee1db966',
        'sounds/reveal.wav' => '9fd77885509c4d390e1eb61a3419d328096856ba1a9558e3609a2ba4e8f0b3a9',
        'sounds/tick.wav' => '3d939c6d1560985805609429d8e5cbae3b83648b031586dbdc870232f855cf5e',
    ],

];
