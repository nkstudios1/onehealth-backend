<?php

use Illuminate\Support\Facades\Schedule;

// Needs one server cron entry:  * * * * * php /path/to/artisan schedule:run
Schedule::command('onehealth:prune')->daily();
Schedule::command('onehealth:process-access')->everyMinute();
