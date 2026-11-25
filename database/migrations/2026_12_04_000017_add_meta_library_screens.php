<?php

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Home, Starred, Recent and Shared with me in the Meta studio app's menu: Home first, the three
 * lists between Metas and Deleted metas.
 */
return new class extends Migration
{
    /** code => [label, path, icon, description] in menu order; metas and deleted metas are there already. */
    private const SCREENS = [
        'screen_metas_home' => ['Home', '/metas/home', 'home', 'What you worked on lately, starred and shared with you.'],
        'screen_metas' => null,
        'screen_starred_metas' => ['Starred', '/metas/starred', 'star', 'Metas you starred.'],
        'screen_recent_metas' => ['Recent', '/metas/recent', 'clock', 'Metas you opened lately.'],
        'screen_shared_metas' => ['Shared with me', '/metas/shared', 'share', 'Metas others shared with you.'],
        'screen_deleted_metas' => null,
    ];

    public function up(): void
    {
        $app = Meta::where('code', 'meta_studio_app')->first();
        $screenType = MetaType::where('code', 'screen')->value('id');
        if (! $app || ! $screenType) {
            return;
        }

        DB::transaction(function () use ($app, $screenType) {
            $order = 1;
            $placed = [];
            foreach (self::SCREENS as $code => $screen) {
                if ($screen === null) {
                    $id = Meta::where('code', $code)->value('id');
                    $item = $id ? MetaParam::where('main_meta_id', $app->id)->where('ref_meta_id', $id)->first() : null;
                    $item?->update(['order_number' => $order]);
                } else {
                    [$label, $path, $icon, $description] = $screen;
                    $meta = Meta::updateOrCreate(['code' => $code], [
                        'name' => $label, 'type_id' => $screenType, 'description' => $description,
                        'json_config' => ['path' => $path], 'is_active' => true,
                    ]);
                    $item = MetaParam::updateOrCreate(['main_meta_id' => $app->id, 'ref_meta_id' => $meta->id], [
                        'order_number' => $order, 'field_path' => substr($code, 7), 'param_name' => substr($code, 7),
                        'label_name' => $label, 'data_type' => 'string', 'icon_cls' => $icon, 'is_show' => true,
                    ]);
                }
                if ($item) {
                    $placed[] = $item->id;
                    $order++;
                }
            }
            // The rest of the menu (widget library, websites…) after these, in the order it had.
            foreach (MetaParam::where('main_meta_id', $app->id)->whereNull('parent_id')->whereNotIn('id', $placed)->orderBy('order_number')->get() as $item) {
                $item->update(['order_number' => $order++]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $ids = Meta::whereIn('code', ['screen_metas_home', 'screen_starred_metas', 'screen_recent_metas', 'screen_shared_metas'])->pluck('id');
            MetaParam::whereIn('ref_meta_id', $ids)->delete();
            Meta::whereIn('id', $ids)->delete();
        });
    }
};
