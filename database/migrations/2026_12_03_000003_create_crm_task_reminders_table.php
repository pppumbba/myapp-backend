<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-up reminders of the CRM app (metas: 2026_12_03_000004_create_crm_reports_and_reminders).
 *
 * Every morning a scheduled form saves one row here for each open follow-up (crm_tasks) that
 * is due or overdue, and saving it notifies the follow-up's owner. One row per follow-up per
 * day (the unique key), so a rerun on the same day doesn't remind twice; the rows stay as a
 * log of what was sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_task_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('crm_tasks')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('crm_customers')->nullOnDelete();
            $table->unsignedBigInteger('owner_id')->nullable()->index();
            $table->string('title');
            $table->date('due_on');
            $table->date('reminded_on');
            $table->timestamps();
            $table->unique(['task_id', 'reminded_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_task_reminders');
    }
};
