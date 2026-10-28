<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Every hour: close abandoned lobbies, delete the player photos whose time is up (lamma.photo_retention_hours: they can be pictures of children, so
// they must not outlive it by a day) and forget guest tokens that can no longer be claimed. Needs the scheduler (`php artisan schedule:work` locally,
// a `schedule:run` cron entry on a server).
Schedule::command('lamma:prune')->hourly();
