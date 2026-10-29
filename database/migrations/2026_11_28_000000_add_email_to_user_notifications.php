<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A notification can also go out by email: whether it is queued, sent or failed, and why.
     */
    public function up(): void
    {
        Schema::table('user_notifications', function (Blueprint $table) {
            $table->string('email_status', 10)->nullable()->after('link');
            $table->string('email_error')->nullable()->after('email_status');
            $table->timestamp('emailed_at')->nullable()->after('email_error');
        });
    }

    public function down(): void
    {
        Schema::table('user_notifications', function (Blueprint $table) {
            $table->dropColumn(['email_status', 'email_error', 'emailed_at']);
        });
    }
};
