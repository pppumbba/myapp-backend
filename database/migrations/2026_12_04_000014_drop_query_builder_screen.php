<?php

use App\Models\Meta;
use App\Models\MetaParam;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The query builder is a full-screen page like the studio (opened from a query meta), so the
 * Meta studio app drops its screen and menu item.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $screen = Meta::where('code', 'screen_query_builder')->value('id');
            if (! $screen) {
                return;
            }
            MetaParam::where('ref_meta_id', $screen)->delete();
            DB::table('meta_permissions')->where('meta_id', $screen)->delete();
            Meta::whereKey($screen)->delete();
        });
    }

    public function down(): void
    {
        // The page no longer shows inside an app, so there is nothing to bring back.
    }
};
