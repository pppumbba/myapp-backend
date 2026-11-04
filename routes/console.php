<?php

use App\Models\Company;
use App\Models\Customer;
use App\Models\Schedule as ScheduleModel;
use App\Support\Contents;
use App\Support\CoreStructure;
use App\Support\CustomerAdopt;
use App\Support\CustomerSchema;
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

Schedule::command('customers:each forms:run-scheduled')->everyMinute()->withoutOverlapping();

/** Deletes uploads that no record holds, a day after they came: forms closed without saving. */
Artisan::command('contents:prune', function () {
    $this->info(Contents::pruneStale().' unattached files deleted.');
})->purpose('Delete uploads never saved to a record');

Schedule::command('customers:each contents:prune')->daily();

/** Reads market companies from ХУР again: a liquidated one is frozen. Each is looked at weekly. */
Artisan::command('market:recheck-companies', function () {
    $due = Company::where('checked_at', '<', now()->subDays(7))->orderBy('checked_at')->limit(200)->get();
    $changed = $due->filter(fn (Company $company) => Companies::recheck($company))->count();
    $this->line("{$due->count()} companies checked, {$changed} changed.");
})->purpose('Check market companies against ХУР again');

Schedule::command('customers:each market:recheck-companies --only='.config('customers.first_code'))->daily();

/** Pays out escrow steps the vendor asked for and the buyer left unanswered; frozen ones wait. */
Artisan::command('market:release-due', function () {
    $this->line(Escrow::autoRelease().' payment steps released.');
})->purpose('Release requested escrow steps whose wait is over');

Schedule::command('customers:each market:release-due --only='.config('customers.first_code'))->hourly();

/** Closes tenders past their close date and ends final-offer rounds past theirs. */
Artisan::command('market:close-tenders', function () {
    $this->line(Tenders::closeDue().' tenders moved.');
})->purpose('Close tenders and final rounds whose time is up');

Schedule::command('customers:each market:close-tenders --only='.config('customers.first_code'))->everyMinute()->withoutOverlapping();

/** Writes database/schema/customer-core.sql from this schema: what every new customer schema starts from. */
Artisan::command('customers:dump-core', function () {
    file_put_contents(database_path(CoreStructure::FILE), CoreStructure::dump());
    $this->info('Wrote '.database_path(CoreStructure::FILE));
})->purpose('Write the core structure new customer schemas are made from');

/**
 * Runs the customer migrations in every customer's schema (or one, by code), going on after a
 * failure; the main schema's own migrations run apart (migrate --database=main --path=database/migrations/main).
 */
Artisan::command('customers:migrate {--customer= : Only this customer code}', function () {
    $failed = false;
    $customers = Customer::query()->when($this->option('customer'), fn ($q, $code) => $q->where('code', $code))->orderBy('id')->get();
    foreach ($customers as $customer) {
        try {
            CustomerSchema::run($customer, fn () => Artisan::call('migrate', ['--database' => 'customer', '--path' => 'database/migrations', '--force' => true]));
            $this->line("{$customer->code}: ok");
        } catch (Throwable $e) {
            $failed = true;
            $this->error("{$customer->code}: FAILED {$e->getMessage()}");
        }
    }

    return $failed ? 1 : 0;
})->purpose('Migrate every customer schema');

/**
 * Runs an artisan command once in every customer (or only one, by code), each inside its schema;
 * a failure in one is reported and the others still run. The scheduler runs customer work this way.
 */
Artisan::command('customers:each {name : The command to run} {--only= : Only this customer code}', function (string $name) {
    $failed = false;
    $customers = Customer::query()->when($this->option('only'), fn ($q, $code) => $q->where('code', $code))->orderBy('id')->get();
    foreach ($customers as $customer) {
        try {
            CustomerSchema::run($customer, fn () => Artisan::call($name, [], $this->output));
        } catch (Throwable $e) {
            $failed = true;
            $this->error("{$customer->code}: FAILED {$e->getMessage()}");
        }
    }

    return $failed ? 1 : 0;
})->purpose('Run a command in every customer');

/**
 * Makes the database from before customers the first customer (CustomerAdopt). DB_MAIN_DATABASE
 * must name a new database. --dry-run only says what would move. Take a dump first.
 */
Artisan::command('customers:adopt {--name= : The first customer\'s name} {--dry-run}', function () {
    $plan = CustomerAdopt::plan();
    foreach ($plan['tables'] as $table => $rows) {
        $this->line("{$table}: {$rows} rows");
    }
    $this->line("{$plan['users']} users and {$plan['tokens']} tokens join the first customer.");
    if ($this->option('dry-run')) {
        return 0;
    }
    if (! $this->option('name')) {
        $this->error('Give the first customer a --name.');

        return 1;
    }
    $customer = CustomerAdopt::run($this->option('name'));
    $this->info("Adopted as customer {$customer->code} (schema {$customer->schema_name}).");

    return 0;
})->purpose('Make the existing database the first customer');
