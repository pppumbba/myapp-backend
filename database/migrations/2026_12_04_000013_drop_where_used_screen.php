<?php

use App\Models\Meta;
use App\Models\MetaParam;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Where used moved from its own page (/metas/usage) into a section of each meta's page, so the
 * Meta studio app drops its screen and menu item.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $screen = Meta::where('code', 'screen_meta_usage')->value('id');
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
        // The page is gone, so there is nothing to bring back.
    }
};
