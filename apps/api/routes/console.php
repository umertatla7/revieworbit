<?php

use App\Domain\Messaging\Services\DueMessageDispatcher;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn (): int => app(DueMessageDispatcher::class)->dispatch())
    ->name('dispatch-due-messages')
    ->everyMinute()
    ->withoutOverlapping();
