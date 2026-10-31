<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tables of the Maple Hill School demo website (a fictional school):
 *  - school_enquiries: the admissions enquiry form on /admissions;
 *  - school_visit_requests: the visit booking form on /contact;
 *  - school_events: the calendar on /events, seeded with a few dates of the coming school year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_enquiries', function (Blueprint $table) {
            $table->id();
            $table->string('parent_name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('child_name')->nullable();
            $table->string('stage');
            $table->string('start_year')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });

        Schema::create('school_visit_requests', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->date('visit_date');
            $table->unsignedSmallInteger('people')->default(1);
            $table->text('message')->nullable();
            $table->timestamps();
        });

        Schema::create('school_events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->date('starts_on');
            $table->string('time_text')->nullable();
            $table->string('place')->nullable();
            $table->string('audience')->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('school_events')->insert(array_map(fn ($e) => array_combine(['title', 'starts_on', 'time_text', 'place', 'audience'], $e) + ['created_at' => $now, 'updated_at' => $now], [
            ['Open morning', '2026-11-14', '09:30 to 12:00', 'Main hall', 'Families thinking of joining'],
            ['Parent and teacher meetings', '2026-11-26', '16:00 to 19:00', 'Classrooms', 'Current families'],
            ['Winter concert', '2026-12-17', '18:00 to 19:30', 'Main hall', 'Everyone welcome'],
            ['Last day of autumn term', '2026-12-18', 'School ends at 13:00', null, 'All students'],
            ['Spring term begins', '2027-01-06', '08:30', null, 'All students'],
            ['Open morning', '2027-01-23', '09:30 to 12:00', 'Main hall', 'Families thinking of joining'],
            ['Applications close for September 2027', '2027-02-26', null, null, 'Families applying'],
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('school_events');
        Schema::dropIfExists('school_visit_requests');
        Schema::dropIfExists('school_enquiries');
    }
};
