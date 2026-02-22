<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Poll Cashfree status periodically to converge non-terminal payouts to terminal states.
Schedule::command('payouts:track-status')
    ->everyFiveMinutes()
    ->withoutOverlapping();
