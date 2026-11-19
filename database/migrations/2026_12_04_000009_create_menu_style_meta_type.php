<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The menu style meta type: a named look for an app's side menu (json_config.menu, see
 * MenuStyles), made once and picked by any app through json_config.menu_style_meta_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('meta_types')->insertOrIgnore([
            'code' => 'menu_style',
            'name' => 'Menu style',
            'is_active' => true,
            'description' => 'A look for an app’s side menu: background, edge, corners, gap, width and items. Made once, picked by any app.',
        ]);
    }

    public function down(): void
    {
        DB::table('meta_types')->where('code', 'menu_style')->delete();
    }
};
