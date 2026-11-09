<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('invoices:mark-overdue')->dailyAt('01:00');

Schedule::command('conversations:retain')->dailyAt('02:00');

Schedule::command('replies:expire-paused')->dailyAt('03:00');
