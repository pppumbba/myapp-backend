<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier types (local, foreign, service). The supplier form's payment terms apply only to
 * some types, through the terms detail's criteria.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proc_supplier_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::table('proc_suppliers', function (Blueprint $table) {
            $table->foreignId('supplier_type_id')->nullable()->after('name')->constrained('proc_supplier_types')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('proc_suppliers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_type_id');
        });
        Schema::dropIfExists('proc_supplier_types');
    }
};
