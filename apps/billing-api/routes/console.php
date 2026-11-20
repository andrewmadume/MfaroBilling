<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('billing:tick')->everyMinute()->withoutOverlapping(5);
Schedule::command('billing:deliver')->everyMinute()->withoutOverlapping(5);
Schedule::command('billing:reconcile')->hourly()->withoutOverlapping(60);
Schedule::command('queue:work --stop-when-empty --max-time=40 --tries=3')->everyMinute()->withoutOverlapping(5);
