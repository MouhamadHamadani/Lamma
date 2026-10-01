<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Once a day: close abandoned lobbies and forget guest tokens that can no longer be claimed. Needs the scheduler (`php artisan schedule:work`
// locally, a `schedule:run` cron entry on a server).
Schedule::command('lamma:prune')->daily();
