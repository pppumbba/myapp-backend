<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A small company for the payroll app, so it does not open empty: three departments, six made-up
 * employees (one joined mid-month, one has left) and three runs: two months ago and last month
 * paid, this month in draft.
 *
 * Everything goes through the triggers, the way the app's stage queries do it. No employee is
 * linked to a login, and no user is given a payroll role. Names, register numbers and accounts
 * are invented; employees and runs start DEMO, clear of the app's own numbering.
 *
 * Only runs once, on a database with no departments yet. MySQL/MariaDB only.
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: int, 6: array<string, ?int>}> no, last, first, department, position, salary, recurring items */
    private const EMPLOYEES = [
        ['DEMO-E01', 'Batbayar', 'Enkhjin', 'OPS', 'Operations manager', 3200000, ['RESP' => 300000, 'MEAL' => 90000]],
        ['DEMO-E02', 'Dorj', 'Nomin', 'OPS', 'Shift supervisor', 2100000, ['MEAL' => 90000, 'TRANSPORT' => 60000]],
        ['DEMO-E03', 'Ganbold', 'Tuvshin', 'SALES', 'Sales lead', 2600000, ['MEAL' => 90000, 'LOAN' => 150000]],
        ['DEMO-E04', 'Sukh', 'Anu', 'SALES', 'Sales assistant', 1500000, ['MEAL' => 90000]],
        ['DEMO-E05', 'Tumur', 'Bilguun', 'FIN', 'Accountant', 2400000, ['MEAL' => 90000]],
    ];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) || DB::table('pay_departments')->exists() || ! DB::table('pay_rates')->exists()) {
            return;
        }

        DB::transaction(function () {
            $now = now();
            $user = DB::table('users')->orderBy('id')->value('id');
            $thisMonth = DB::selectOne("SELECT DATE_FORMAT(CURDATE(), '%Y-%m-01') AS d")->d;

            $departments = [];
            foreach ([['OPS', 'Operations'], ['SALES', 'Sales'], ['FIN', 'Finance']] as [$code, $name]) {
                $departments[$code] = DB::table('pay_departments')->insertGetId(['code' => $code, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
            }
            $components = DB::table('pay_components')->pluck('id', 'code')->all();

            foreach (self::EMPLOYEES as $i => [$no, $last, $first, $department, $position, $salary, $items]) {
                $id = $this->employee($no, $last, $first, $departments[$department], $position, $salary, date('Y-m-d', strtotime('-3 years', strtotime($thisMonth))), null, $i + 1);
                foreach ($items as $code => $amount) {
                    DB::table('pay_employee_components')->insert(['employee_id' => $id, 'component_id' => $components[$code], 'amount' => $amount, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
            // Left at the middle of last month; joined in the middle of last month.
            $lastMonth = date('Y-m-d', strtotime('-1 month', strtotime($thisMonth)));
            $this->employee('DEMO-E06', 'Erdene', 'Saraa', $departments['SALES'], 'Sales assistant', 1400000, date('Y-m-d', strtotime('-2 years', strtotime($thisMonth))), date('Y-m-15', strtotime($lastMonth)), 6);
            $this->employee('DEMO-E07', 'Otgon', 'Munkh', $departments['FIN'], 'Junior accountant', 1600000, date('Y-m-16', strtotime($lastMonth)), null, 7);

            $this->run('DEMO-PR-1', date('Y-m-d', strtotime('-2 months', strtotime($thisMonth))), 'paid', $user, ['DEMO-E01' => ['BONUS', 500000]]);
            $this->run('DEMO-PR-2', $lastMonth, 'paid', $user, ['DEMO-E03' => ['OVERTIME', 120000]]);
            $this->run('DEMO-PR-3', $thisMonth, 'draft', $user, []);
        });
    }

    public function down(): void
    {
        // Paid runs are final by design: their triggers keep them.
    }

    private function employee(string $no, string $last, string $first, int $department, string $position, int $salary, string $hired, ?string $left, int $n): int
    {
        return DB::table('pay_employees')->insertGetId([
            'employee_no' => $no, 'last_name' => $last, 'first_name' => $first, 'department_id' => $department, 'position' => $position, 'base_salary' => $salary,
            'hire_date' => $hired, 'end_date' => $left, 'register_no' => sprintf('DEMO%06d', $n), 'bank_name' => 'Demo Bank', 'bank_account' => sprintf('50000000%02d', $n),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** One month from start to $status, the way pay_generate_slips and the Approve and Paid buttons do it. $oneOff: employee_no => [pay item, amount]. */
    private function run(string $number, string $start, string $status, ?int $user, array $oneOff): void
    {
        $end = date('Y-m-t', strtotime($start));
        $id = DB::table('pay_runs')->insertGetId([
            'run_no' => $number, 'period_start' => $start, 'pay_date' => date('Y-m-25', strtotime($start)), 'working_days' => 22, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $onBooks = fn ($q) => $q->where('hire_date', '<=', $end)->where(fn ($w) => $w->whereNull('end_date')->orWhere('end_date', '>=', $start));

        foreach (DB::table('pay_employees')->where($onBooks)->orderBy('id')->pluck('id') as $employee) {
            DB::table('pay_slips')->insert(['run_id' => $id, 'employee_id' => $employee, 'created_at' => now(), 'updated_at' => now()]);
            foreach (DB::table('pay_employee_components')->where('employee_id', $employee)->get() as $item) {
                DB::table('pay_slip_lines')->insert([
                    'run_id' => $id, 'employee_id' => $employee, 'component_id' => $item->component_id, 'amount' => $item->amount, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        foreach ($oneOff as $no => [$code, $amount]) {
            DB::table('pay_slip_lines')->insert([
                'run_id' => $id, 'employee_id' => DB::table('pay_employees')->where('employee_no', $no)->value('id'),
                'component_id' => DB::table('pay_components')->where('code', $code)->value('id'), 'amount' => $amount, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        if ($status === 'paid') {
            DB::table('pay_runs')->where('id', $id)->update(['status' => 'approved', 'approved_by' => $user]);
            DB::table('pay_runs')->where('id', $id)->update(['status' => 'paid', 'paid_by' => $user]);
        }
    }
};
