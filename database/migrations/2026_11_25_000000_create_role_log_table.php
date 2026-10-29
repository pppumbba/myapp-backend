<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * role_log: what changed about a role, wherever it was changed (its workspace, a user's
 * roles, a meta's or workflow's permissions): one row per save, see RoleLog.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            // role, users, metas, statuses
            $table->string('action', 10);
            // role: fields as [old, new]; users: added and removed; metas and statuses: rows as before and after.
            $table->json('changes');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['role_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_log');
    }
};
