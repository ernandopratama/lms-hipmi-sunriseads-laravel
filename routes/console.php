<?php
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
Artisan::command('sunrise:status', function () {
 $this->info('Sunrise Academy — Laravel / MySQL / Vue.');
});

// A bounded worker inside the scheduler process: no Supervisor, Redis,
// shell process spawning or persistent daemon is required on shared hosting.
Schedule::call(function () {
    if (config('queue.default') === 'database') {
        Artisan::call('queue:work', [
            'connection' => 'database', '--stop-when-empty' => true,
            '--max-time' => 45, '--max-jobs' => 20, '--tries' => 3,
            '--timeout' => 30, '--sleep' => 0,
        ]);
    }
})->name('shared-hosting-queue')->everyMinute()->withoutOverlapping(5);
