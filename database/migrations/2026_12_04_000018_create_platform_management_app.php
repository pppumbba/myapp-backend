<?php

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The pages that look after the platform rather than build metas move out of Meta studio into an
 * app of their own, Platform management. Meta studio keeps Home, Metas, Starred, Recent, Shared
 * with me and Deleted metas. Like the other built-in apps: no permission rows, so admins only.
 */
return new class extends Migration
{
    /** Moved, in this menu order: screen code => [label, icon]. */
    private const SCREENS = [
        'screen_widgets' => ['Widget library', 'package'],
        'screen_sites' => ['Websites', 'home'],
        'screen_schedules' => ['Schedules', 'calendar'],
        'screen_patches' => ['Patches', 'folder'],
        'screen_session_values' => ['Session values', 'users'],
    ];

    public function up(): void
    {
        $studio = Meta::where('code', 'meta_studio_app')->first();
        $appType = MetaType::where('code', 'app')->value('id');
        if (! $studio || ! $appType) {
            return;
        }

        DB::transaction(function () use ($studio, $appType) {
            $platform = Meta::updateOrCreate(['code' => 'platform_app'], [
                'name' => 'Platform management',
                'type_id' => $appType,
                'description' => 'Widgets, websites, schedules, patches and session values.',
                'is_active' => true,
            ]);
            // An item still in Meta studio moves; one taken out of its menu by hand is made again.
            $screens = Meta::whereIn('code', array_keys(self::SCREENS))->pluck('id', 'code');
            $order = 1;
            foreach (self::SCREENS as $code => [$label, $icon]) {
                if (! isset($screens[$code])) {
                    continue;
                }
                $item = MetaParam::whereIn('main_meta_id', [$studio->id, $platform->id])->where('ref_meta_id', $screens[$code])->first();
                if ($item) {
                    $item->update(['main_meta_id' => $platform->id, 'order_number' => $order]);
                } else {
                    MetaParam::create([
                        'main_meta_id' => $platform->id, 'order_number' => $order, 'field_path' => substr($code, 7), 'param_name' => substr($code, 7),
                        'label_name' => $label, 'data_type' => 'string', 'icon_cls' => $icon, 'ref_meta_id' => $screens[$code], 'is_show' => true,
                    ]);
                }
                $order++;
            }
            $studio->update(['description' => 'Build apps: your metas, starred, recent, shared with you and deleted.']);
        });
    }

    public function down(): void
    {
        $studio = Meta::where('code', 'meta_studio_app')->first();
        $platform = Meta::where('code', 'platform_app')->first();
        if (! $studio || ! $platform) {
            return;
        }

        DB::transaction(function () use ($studio, $platform) {
            $next = (int) MetaParam::where('main_meta_id', $studio->id)->max('order_number');
            foreach (MetaParam::where('main_meta_id', $platform->id)->orderBy('order_number')->get() as $item) {
                $item->update(['main_meta_id' => $studio->id, 'order_number' => ++$next]);
            }
            DB::table('meta_permissions')->where('meta_id', $platform->id)->delete();
            $platform->delete();
            $studio->update(['description' => 'Build apps: metas, queries, widgets, websites, schedules and patches.']);
        });
    }
};
