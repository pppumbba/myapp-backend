<?php

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Deleted metas get their own page in the Meta studio app's menu, right after Metas (a screen
 * meta at /metas/deleted), instead of a button on the Metas page.
 */
return new class extends Migration
{
    public function up(): void
    {
        $app = Meta::where('code', 'meta_studio_app')->first();
        $screenType = MetaType::where('code', 'screen')->value('id');
        if (! $app || ! $screenType) {
            return;
        }

        DB::transaction(function () use ($app, $screenType) {
            $screen = Meta::updateOrCreate(['code' => 'screen_deleted_metas'], [
                'name' => 'Deleted metas',
                'type_id' => $screenType,
                'description' => 'Deleted metas, to restore or delete for good.',
                'json_config' => ['path' => '/metas/deleted'],
                'is_active' => true,
            ]);
            if (MetaParam::where('main_meta_id', $app->id)->where('ref_meta_id', $screen->id)->exists()) {
                return;
            }
            MetaParam::where('main_meta_id', $app->id)->whereNull('parent_id')->where('order_number', '>=', 2)->increment('order_number');
            MetaParam::create([
                'main_meta_id' => $app->id,
                'order_number' => 2,
                'field_path' => 'deleted_metas',
                'param_name' => 'deleted_metas',
                'label_name' => 'Deleted metas',
                'data_type' => 'string',
                'icon_cls' => 'trash',
                'ref_meta_id' => $screen->id,
                'is_show' => true,
            ]);
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $screen = Meta::where('code', 'screen_deleted_metas')->value('id');
            if ($screen) {
                MetaParam::where('ref_meta_id', $screen)->delete();
                Meta::whereKey($screen)->delete();
            }
        });
    }
};
