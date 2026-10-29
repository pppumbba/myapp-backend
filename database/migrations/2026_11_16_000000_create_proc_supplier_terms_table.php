<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A supplier's payment terms: one-to-one with proc_suppliers (supplier_id is unique), edited
 * as a section of the supplier form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proc_supplier_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->unique()->constrained('proc_suppliers')->cascadeOnDelete();
            $table->string('payment_terms')->default('net_30');
            $table->string('currency', 3)->default('MNT');
            $table->decimal('credit_limit', 15, 2)->nullable();
            $table->string('tax_number')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proc_supplier_terms');
    }
};
