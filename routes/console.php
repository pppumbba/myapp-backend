<?php

use App\Models\Schedule as ScheduleModel;
use App\Support\Contents;
use App\Support\ScheduledForms;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Runs the scheduled forms that are due now, or with a code, that schedule now whether or
 * not it is due. The scheduler below calls it every minute.
 */
Artisan::command('forms:run-scheduled {code? : Run this schedule now, due or not}', function (?string $code = null) {
    if ($code !== null) {
        $schedule = ScheduleModel::where('code', $code)->first();
        if (! $schedule) {
            $this->error("There is no schedule \"{$code}\".");

            return 1;
        }
        $results = [$code => ScheduledForms::runSchedule($schedule)];
    } else {
        $results = ScheduledForms::runDue();
    }

    foreach ($results as $scheduleCode => $runs) {
        foreach ($runs as $run) {
            $this->line("{$scheduleCode}: {$run['process']} over {$run['view']}: {$run['created']} saved, ".count($run['failed']).' failed.'.($run['message'] ? " {$run['message']}" : ''));
        }
    }

    return 0;
})->purpose('Run the scheduled forms that are due');

Schedule::command('forms:run-scheduled')->everyMinute()->withoutOverlapping();

/** Deletes uploads that no record holds, a day after they came: forms closed without saving. */
Artisan::command('contents:prune', function () {
    $this->info(Contents::pruneStale().' unattached files deleted.');
})->purpose('Delete uploads never saved to a record');

Schedule::command('contents:prune')->daily();
