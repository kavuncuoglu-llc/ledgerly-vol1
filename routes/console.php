<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('invoices:mark-overdue')->dailyAt('01:00');
