<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'main';

    /** users.avatar: the profile picture's file name in storage/app/avatars (AvatarController); null: initials. */
    public function up(): void
    {
        Schema::connection('main')->table('users', function (Blueprint $table) {
            $table->string('avatar', 80)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::connection('main')->table('users', function (Blueprint $table) {
            $table->dropColumn('avatar');
        });
    }
};
