<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Websites (see App\Support\Sites): a website meta's params are its pages. site_domains are the
 * host names it answers on, site_releases what each page showed from when (a scheduled one
 * waits for its publish_at; a release without a snapshot takes the page down), and
 * site_visits a count of page views per day.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('meta_types')->insertOrIgnore([
            'code' => 'website',
            'name' => 'Website',
            'description' => 'A public website: pages from the page builder with addresses, a menu, its own domains, publishing and visit counts.',
            'is_active' => true,
        ]);

        Schema::create('site_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_meta_id')->constrained('metas')->cascadeOnDelete();
            $table->string('host', 190)->unique();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        Schema::create('site_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_meta_id')->constrained('metas')->cascadeOnDelete();
            // The page's entry on the site; kept as history when the entry goes.
            $table->foreignId('param_id')->nullable()->constrained('meta_params')->nullOnDelete();
            $table->foreignId('page_meta_id')->nullable()->constrained('metas')->nullOnDelete();
            $table->string('path')->nullable();
            $table->json('snapshot')->nullable();
            $table->string('hash', 40)->nullable();
            $table->json('checks')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('restored_from_id')->nullable()->constrained('site_releases')->nullOnDelete();
            $table->timestamp('publish_at')->index();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['param_id', 'publish_at']);
        });

        Schema::create('site_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_meta_id')->constrained('metas')->cascadeOnDelete();
            $table->string('path');
            $table->date('day');
            $table->unsignedInteger('views')->default(0);
            $table->unique(['site_meta_id', 'path', 'day']);
            $table->index(['site_meta_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_visits');
        Schema::dropIfExists('site_releases');
        Schema::dropIfExists('site_domains');
        DB::table('meta_types')->where('code', 'website')->delete();
    }
};
