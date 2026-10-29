<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The climbing wall demo website: its daily sessions, the bookings visitors send, and how busy each hour usually is. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('climb_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('starts_at', 5);
            $table->string('ends_at', 5);
            $table->string('name');
            $table->string('area');
            $table->unsignedSmallInteger('capacity');
        });
        Schema::create('climb_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('climb_sessions')->cascadeOnDelete();
            $table->date('booking_date')->index();
            $table->string('name');
            $table->string('email');
            $table->unsignedSmallInteger('people')->default(1);
            $table->timestamps();
        });
        Schema::create('climb_busy_hours', function (Blueprint $table) {
            $table->id();
            $table->string('day_kind', 8);
            $table->string('hour', 2);
            $table->unsignedSmallInteger('climbers');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('climb_busy_hours');
        Schema::dropIfExists('climb_bookings');
        Schema::dropIfExists('climb_sessions');
    }
};
