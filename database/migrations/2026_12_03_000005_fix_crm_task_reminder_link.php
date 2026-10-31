<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The follow-up reminder's link (2026_12_03_000004) is /run/{tasks grid}?record={task_id}, and
 * a notification fills a lookup field with its name, so the link got the follow-up's title
 * instead of its id. The reminder form is only saved by the schedule, so its task_id becomes
 * a plain hidden number; the "Reminders sent" grid still shows the follow-up's title.
 *
 * Idempotent: matched by the form's code and the field.
 */
return new class extends Migration
{
    public function up(): void
    {
        $form = DB::table('metas')->where('code', 'crm_task_reminder_form')->value('id');
        if (! $form) {
            return;
        }
        DB::transaction(function () use ($form) {
            DB::table('meta_params')->where('main_meta_id', $form)->where('field_path', 'task_id')->update([
                'is_select' => false, 'ref_meta_id' => null, 'render_type' => null, 'display_field' => null, 'is_show' => false,
            ]);
        });
    }

    public function down(): void
    {
        $form = DB::table('metas')->where('code', 'crm_task_reminder_form')->value('id');
        $tasks = DB::table('metas')->where('code', 'crm_tasks_grid')->value('id');
        if (! $form || ! $tasks) {
            return;
        }
        DB::transaction(function () use ($form, $tasks) {
            DB::table('meta_params')->where('main_meta_id', $form)->where('field_path', 'task_id')->update([
                'is_select' => true, 'ref_meta_id' => $tasks, 'render_type' => 'lookup', 'display_field' => 'title', 'is_show' => true,
            ]);
        });
    }
};
