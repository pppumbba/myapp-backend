<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The rules of the payroll app (tables: 2026_12_03_000027), in triggers so no way of saving skips them.
 *
 * - A run is for one calendar month, one per month unless voided, and copies the rates in force.
 *   Its month, working days and rates never change: to redo them, void it and start again.
 * - A slip copies the employee (an old payslip keeps its name, bank and department) and works out:
 *     base pay  = base salary x worked days / working days
 *     gross     = base pay + earnings
 *     insurance = insurable pay, up to the monthly cap, x the rate (employee's and employer's)
 *     taxable   = base pay + taxable earnings - employee insurance
 *     income tax= taxable x the rate - the monthly credit, not below zero
 *     net       = gross - employee insurance - income tax - deductions
 *   Worked days start from the employee's dates in the month, so a joiner or leaver is not paid
 *   in full by default; they can be changed on the slip.
 * - Lines copy their pay item's name, kind and flags, and keep the slip right; the slip keeps the run's
 *   totals right. Only a draft run's slips and lines change.
 * - A run needs slips, no negative net pay and a pay date to be approved. Approved goes back to draft,
 *   on to paid, or to void with a reason. Paid is final: corrections go in a later month.
 *
 * MySQL refuses a trigger that updates a table its statement reads, so whatever fills slips or lines
 * in bulk must not select from pay_runs (for slips) or pay_slips (for lines): see pay_generate_slips.
 *
 * MySQL/MariaDB only; SQLite tests keep the tables without the rules.
 */
return new class extends Migration
{
    /** @return list<string> */
    private function names(): array
    {
        return [
            'pay_employees_before_insert', 'pay_employees_before_update',
            'pay_runs_before_insert', 'pay_runs_before_update', 'pay_runs_before_delete',
            'pay_slips_before_insert', 'pay_slips_before_update', 'pay_slips_before_delete',
            'pay_slips_after_insert', 'pay_slips_after_update', 'pay_slips_after_delete',
            'pay_slip_lines_before_insert', 'pay_slip_lines_before_update', 'pay_slip_lines_before_delete',
            'pay_slip_lines_after_insert', 'pay_slip_lines_after_update', 'pay_slip_lines_after_delete',
        ];
    }

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        foreach ($this->names() as $name) {
            DB::unprepared("DROP TRIGGER IF EXISTS `{$name}`");
        }
        $this->employees();
        $this->runs();
        $this->slips();
        $this->lines();
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        foreach ($this->names() as $name) {
            DB::unprepared("DROP TRIGGER IF EXISTS `{$name}`");
        }
    }

    private function employees(): void
    {
        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $event) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER pay_employees_before_{$suffix} BEFORE {$event} ON pay_employees FOR EACH ROW
                BEGIN
                  SET NEW.full_name = CONCAT(NEW.last_name, ' ', NEW.first_name);
                END
                SQL);
        }
    }

    private function runs(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER pay_runs_before_insert BEFORE INSERT ON pay_runs FOR EACH ROW
            BEGIN
              DECLARE v_rate BIGINT UNSIGNED;
              DECLARE v_a DECIMAL(5,2);
              DECLARE v_b DECIMAL(5,2);
              DECLARE v_c DECIMAL(14,2);
              DECLARE v_d DECIMAL(5,2);
              DECLARE v_e DECIMAL(14,2);
              SET NEW.period_start = DATE_SUB(NEW.period_start, INTERVAL DAYOFMONTH(NEW.period_start) - 1 DAY);
              SET NEW.period_end = LAST_DAY(NEW.period_start), NEW.status = 'draft', NEW.employee_count = 0, NEW.gross_total = 0, NEW.si_employee_total = 0, NEW.si_employer_total = 0,
                  NEW.pit_total = 0, NEW.deductions_total = 0, NEW.net_total = 0, NEW.employer_cost_total = 0;
              IF COALESCE(NEW.working_days, 0) <= 0 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A run needs its working days.';
              END IF;
              IF EXISTS (SELECT 1 FROM pay_runs r WHERE r.period_start = NEW.period_start AND r.status != 'void') THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This month already has a payroll run.';
              END IF;
              SET v_rate = (SELECT x.id FROM pay_rates x WHERE x.effective_from <= NEW.period_end ORDER BY x.effective_from DESC, x.id DESC LIMIT 1);
              IF v_rate IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No rates are in force for this month.';
              END IF;
              SELECT x.si_employee_pct, x.si_employer_pct, x.si_monthly_cap, x.pit_pct, x.monthly_tax_credit INTO v_a, v_b, v_c, v_d, v_e FROM pay_rates x WHERE x.id = v_rate;
              SET NEW.si_employee_pct = v_a, NEW.si_employer_pct = v_b, NEW.si_monthly_cap = v_c, NEW.pit_pct = v_d, NEW.monthly_tax_credit = v_e;
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER pay_runs_before_update BEFORE UPDATE ON pay_runs FOR EACH ROW
            BEGIN
              DECLARE v_msg VARCHAR(128);
              SET NEW.period_start = OLD.period_start, NEW.period_end = OLD.period_end, NEW.working_days = OLD.working_days, NEW.si_employee_pct = OLD.si_employee_pct,
                  NEW.si_employer_pct = OLD.si_employer_pct, NEW.si_monthly_cap = OLD.si_monthly_cap, NEW.pit_pct = OLD.pit_pct, NEW.monthly_tax_credit = OLD.monthly_tax_credit;
              IF OLD.status = 'draft' AND NEW.status = 'approved' THEN
                IF NOT EXISTS (SELECT 1 FROM pay_slips s WHERE s.run_id = OLD.id) THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This run has no payslips: generate them first.';
                END IF;
                SET v_msg = (SELECT LEFT(CONCAT(s.employee_name, ': net pay is below zero.'), 128) FROM pay_slips s WHERE s.run_id = OLD.id AND s.net_pay < 0 ORDER BY s.id LIMIT 1);
                IF v_msg IS NOT NULL THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
                END IF;
                IF NEW.pay_date IS NULL THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Set the pay date before approving.';
                END IF;
                SET NEW.approved_at = NOW();
              ELSEIF OLD.status = 'approved' AND NEW.status = 'draft' THEN
                SET NEW.approved_at = NULL, NEW.approved_by = NULL;
              ELSEIF OLD.status = 'approved' AND NEW.status = 'paid' THEN
                SET NEW.paid_at = NOW();
              ELSEIF OLD.status IN ('draft', 'approved') AND NEW.status = 'void' THEN
                IF COALESCE(NEW.void_reason, '') = '' THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Say why the run is voided.';
                END IF;
              ELSEIF NEW.status != OLD.status THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A run goes draft, approved, paid; only a draft or approved run can be voided.';
              ELSEIF OLD.status != 'draft' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only a draft run can be changed.';
              END IF;
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER pay_runs_before_delete BEFORE DELETE ON pay_runs FOR EACH ROW
            BEGIN
              IF OLD.status != 'draft' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only a draft run can be deleted.';
              END IF;
            END
            SQL);
    }

    private function slips(): void
    {
        $declare = <<<'SQL'
            DECLARE v_status VARCHAR(20);
            DECLARE v_days DECIMAL(5,2);
            DECLARE v_start DATE;
            DECLARE v_end DATE;
            DECLARE v_si_e DECIMAL(5,2);
            DECLARE v_si_r DECIMAL(5,2);
            DECLARE v_cap DECIMAL(14,2);
            DECLARE v_pit DECIMAL(5,2);
            DECLARE v_credit DECIMAL(14,2);
            DECLARE v_earn DECIMAL(14,2) DEFAULT 0;
            DECLARE v_taxed DECIMAL(14,2) DEFAULT 0;
            DECLARE v_insured DECIMAL(14,2) DEFAULT 0;
            DECLARE v_deduct DECIMAL(14,2) DEFAULT 0;
            SQL;
        $run = <<<'SQL'
            SELECT r.status, r.working_days, r.period_start, r.period_end, r.si_employee_pct, r.si_employer_pct, r.si_monthly_cap, r.pit_pct, r.monthly_tax_credit
              INTO v_status, v_days, v_start, v_end, v_si_e, v_si_r, v_cap, v_pit, v_credit FROM pay_runs r WHERE r.id = NEW.run_id;
            IF v_status != 'draft' THEN
              SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only the payslips of a draft run can be changed.';
            END IF;
            SQL;
        $amounts = <<<'SQL'
            SET NEW.worked_days = LEAST(GREATEST(COALESCE(NEW.worked_days, v_days), 0), v_days), NEW.base_salary = GREATEST(COALESCE(NEW.base_salary, 0), 0);
            SET NEW.base_pay = ROUND(NEW.base_salary * NEW.worked_days / v_days, 2);
            SELECT COALESCE(SUM(CASE WHEN l.kind = 'earning' THEN l.amount ELSE 0 END), 0),
                   COALESCE(SUM(CASE WHEN l.kind = 'earning' AND l.is_taxable = 1 THEN l.amount ELSE 0 END), 0),
                   COALESCE(SUM(CASE WHEN l.kind = 'earning' AND l.is_insurable = 1 THEN l.amount ELSE 0 END), 0),
                   COALESCE(SUM(CASE WHEN l.kind = 'deduction' THEN l.amount ELSE 0 END), 0)
              INTO v_earn, v_taxed, v_insured, v_deduct
              FROM pay_slip_lines l WHERE l.slip_id = NEW.id;
            SET NEW.earnings_total = v_earn, NEW.deductions_total = v_deduct;
            SET NEW.gross_pay = NEW.base_pay + v_earn;
            SET NEW.insurable_pay = NEW.base_pay + v_insured;
            SET NEW.si_employee = ROUND(LEAST(NEW.insurable_pay, COALESCE(v_cap, NEW.insurable_pay)) * v_si_e / 100, 2);
            SET NEW.si_employer = ROUND(LEAST(NEW.insurable_pay, COALESCE(v_cap, NEW.insurable_pay)) * v_si_r / 100, 2);
            SET NEW.taxable_pay = GREATEST(NEW.base_pay + v_taxed - NEW.si_employee, 0);
            SET NEW.pit = GREATEST(ROUND(NEW.taxable_pay * v_pit / 100, 2) - v_credit, 0);
            SET NEW.net_pay = NEW.gross_pay - NEW.si_employee - NEW.pit - v_deduct;
            SQL;

        DB::unprepared(<<<SQL
            CREATE TRIGGER pay_slips_before_insert BEFORE INSERT ON pay_slips FOR EACH ROW
            BEGIN
              DECLARE v_no VARCHAR(30);
              DECLARE v_name VARCHAR(255);
              DECLARE v_department VARCHAR(255);
              DECLARE v_position VARCHAR(255);
              DECLARE v_bank VARCHAR(255);
              DECLARE v_account VARCHAR(50);
              DECLARE v_salary DECIMAL(14,2);
              DECLARE v_hired DATE;
              DECLARE v_left DATE;
              {$declare}
              {$run}
              SELECT e.employee_no, e.full_name, d.name, e.position, e.bank_name, e.bank_account, e.base_salary, e.hire_date, e.end_date
                INTO v_no, v_name, v_department, v_position, v_bank, v_account, v_salary, v_hired, v_left
                FROM pay_employees e LEFT JOIN pay_departments d ON d.id = e.department_id WHERE e.id = NEW.employee_id;
              IF v_hired IS NULL OR v_end < v_hired OR (v_left IS NOT NULL AND v_left < v_start) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This employee is not on the books in that month.';
              END IF;
              SET NEW.employee_no = v_no, NEW.employee_name = v_name, NEW.department_name = v_department, NEW.position = v_position, NEW.bank_name = v_bank, NEW.bank_account = v_account,
                  NEW.base_salary = COALESCE(NEW.base_salary, v_salary);
              IF NEW.worked_days IS NULL THEN
                SET NEW.worked_days = ROUND(v_days * (DATEDIFF(LEAST(v_end, COALESCE(v_left, v_end)), GREATEST(v_start, v_hired)) + 1) / DAY(v_end) * 2) / 2;
              END IF;
              {$amounts}
            END
            SQL);
        DB::unprepared(<<<SQL
            CREATE TRIGGER pay_slips_before_update BEFORE UPDATE ON pay_slips FOR EACH ROW
            BEGIN
              {$declare}
              {$run}
              SET NEW.run_id = OLD.run_id, NEW.employee_id = OLD.employee_id, NEW.employee_no = OLD.employee_no, NEW.employee_name = OLD.employee_name, NEW.department_name = OLD.department_name,
                  NEW.position = OLD.position, NEW.bank_name = OLD.bank_name, NEW.bank_account = OLD.bank_account;
              {$amounts}
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER pay_slips_before_delete BEFORE DELETE ON pay_slips FOR EACH ROW
            BEGIN
              IF (SELECT r.status FROM pay_runs r WHERE r.id = OLD.run_id) != 'draft' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only the payslips of a draft run can be changed.';
              END IF;
            END
            SQL);
        foreach (['insert' => ['INSERT', 'NEW'], 'update' => ['UPDATE', 'NEW'], 'delete' => ['DELETE', 'OLD']] as $suffix => [$event, $row]) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER pay_slips_after_{$suffix} AFTER {$event} ON pay_slips FOR EACH ROW
                  UPDATE pay_runs r
                  JOIN (SELECT COUNT(*) AS c, COALESCE(SUM(s.gross_pay), 0) AS g, COALESCE(SUM(s.si_employee), 0) AS e, COALESCE(SUM(s.si_employer), 0) AS m, COALESCE(SUM(s.pit), 0) AS p,
                               COALESCE(SUM(s.deductions_total), 0) AS d, COALESCE(SUM(s.net_pay), 0) AS n
                        FROM pay_slips s WHERE s.run_id = {$row}.run_id) x
                  SET r.employee_count = x.c, r.gross_total = x.g, r.si_employee_total = x.e, r.si_employer_total = x.m, r.pit_total = x.p, r.deductions_total = x.d, r.net_total = x.n,
                      r.employer_cost_total = x.g + x.m, r.updated_at = NOW()
                  WHERE r.id = {$row}.run_id
                SQL);
        }
    }

    private function lines(): void
    {
        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $event) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER pay_slip_lines_before_{$suffix} BEFORE {$event} ON pay_slip_lines FOR EACH ROW
                BEGIN
                  DECLARE v_status VARCHAR(20);
                  DECLARE v_run BIGINT UNSIGNED;
                  DECLARE v_employee BIGINT UNSIGNED;
                  DECLARE v_name VARCHAR(255);
                  DECLARE v_kind VARCHAR(20);
                  DECLARE v_taxable TINYINT;
                  DECLARE v_insurable TINYINT;
                  DECLARE v_default DECIMAL(14,2);
                  IF NEW.slip_id IS NULL THEN
                    SET NEW.slip_id = (SELECT s.id FROM pay_slips s WHERE s.run_id = NEW.run_id AND s.employee_id = NEW.employee_id);
                  END IF;
                  SELECT s.run_id, s.employee_id, r.status INTO v_run, v_employee, v_status FROM pay_slips s JOIN pay_runs r ON r.id = s.run_id WHERE s.id = NEW.slip_id;
                  SET NEW.run_id = v_run, NEW.employee_id = v_employee;
                  IF COALESCE(v_status, '') != 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only the payslips of a draft run can be changed.';
                  END IF;
                  SELECT c.name, c.kind, c.is_taxable, c.is_insurable, c.default_amount INTO v_name, v_kind, v_taxable, v_insurable, v_default FROM pay_components c WHERE c.id = NEW.component_id;
                  SET NEW.component_name = v_name, NEW.kind = v_kind, NEW.is_taxable = v_taxable, NEW.is_insurable = v_insurable, NEW.amount = COALESCE(NEW.amount, v_default);
                  IF NEW.amount < 0 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A pay item cannot be negative: use a deduction.';
                  END IF;
                END
                SQL);
        }
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER pay_slip_lines_before_delete BEFORE DELETE ON pay_slip_lines FOR EACH ROW
            BEGIN
              IF (SELECT r.status FROM pay_slips s JOIN pay_runs r ON r.id = s.run_id WHERE s.id = OLD.slip_id) != 'draft' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only the payslips of a draft run can be changed.';
              END IF;
            END
            SQL);
        foreach (['insert' => ['INSERT', 'NEW'], 'update' => ['UPDATE', 'NEW'], 'delete' => ['DELETE', 'OLD']] as $suffix => [$event, $row]) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER pay_slip_lines_after_{$suffix} AFTER {$event} ON pay_slip_lines FOR EACH ROW
                  UPDATE pay_slips s SET s.updated_at = NOW() WHERE s.id = {$row}.slip_id
                SQL);
        }
    }
};
