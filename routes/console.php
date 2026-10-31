<?php

use App\Models\Company;
use App\Models\Schedule as ScheduleModel;
use App\Support\Contents;
use App\Support\Market\Companies;
use App\Support\Market\Escrow;
use App\Support\Market\Tenders;
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

/** Reads market companies from ХУР again: a liquidated one is frozen. Each is looked at weekly. */
Artisan::command('market:recheck-companies', function () {
    $due = Company::where('checked_at', '<', now()->subDays(7))->orderBy('checked_at')->limit(200)->get();
    $changed = $due->filter(fn (Company $company) => Companies::recheck($company))->count();
    $this->line("{$due->count()} companies checked, {$changed} changed.");
})->purpose('Check market companies against ХУР again');

Schedule::command('market:recheck-companies')->daily();

/** Pays out escrow steps the vendor asked for and the buyer left unanswered; frozen ones wait. */
Artisan::command('market:release-due', function () {
    $this->line(Escrow::autoRelease().' payment steps released.');
})->purpose('Release requested escrow steps whose wait is over');

Schedule::command('market:release-due')->hourly();

/** Closes tenders past their close date and ends final-offer rounds past theirs. */
Artisan::command('market:close-tenders', function () {
    $this->line(Tenders::closeDue().' tenders moved.');
})->purpose('Close tenders and final rounds whose time is up');

Schedule::command('market:close-tenders')->everyMinute()->withoutOverlapping();
