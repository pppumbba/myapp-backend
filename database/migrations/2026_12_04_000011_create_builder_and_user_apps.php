<?php

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use Database\Seeders\MetaTypeSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The builder's own pages and user management as meta apps, like the Tender market: each page is
 * a screen meta naming its address, and the app's menu points at them. No permission rows, so
 * admins only; a builder (not an admin) still reaches Metas in the main shell.
 *
 *   Meta studio (meta_studio_app)          User management (users_app)
 *   ├─ Metas           /metas               └─ Users & roles  /admin (and /admin/roles/{id})
 *   ├─ Widget library  /widgets
 *   ├─ Websites        /sites
 *   ├─ Schedules       /schedules
 *   ├─ Patches         /patches
 *   └─ Session values  /session-values
 */
return new class extends Migration
{
    /** @var array<string, array<int, array{0: string, 1: string, 2: string, 3: string, 4: string}>> app code => [screen code, name, path, icon, description] */
    private const APPS = [
        'meta_studio_app' => [
            ['screen_metas', 'Metas', '/metas', 'grid', 'Apps, grids, forms and every other meta.'],
            ['screen_widgets', 'Widget library', '/widgets', 'package', 'Widgets pages are built from.'],
            ['screen_sites', 'Websites', '/sites', 'home', 'Websites, their pages and releases.'],
            ['screen_schedules', 'Schedules', '/schedules', 'calendar', 'Forms and queries run on a schedule.'],
            ['screen_patches', 'Patches', '/patches', 'folder', 'Releases of metas and their installs.'],
            ['screen_session_values', 'Session values', '/session-values', 'users', 'Values read for each user at login.'],
        ],
        'users_app' => [
            ['screen_users', 'Users & roles', '/admin', 'users', 'People, roles and what each role may do.'],
        ],
    ];

    private const NAMES = [
        'meta_studio_app' => ['Meta studio', 'Build apps: metas, queries, widgets, websites, schedules and patches.'],
        'users_app' => ['User management', 'People, roles and permissions.'],
    ];

    public function up(): void
    {
        (new MetaTypeSeeder)->run();
        $types = MetaType::pluck('id', 'code')->all();

        DB::transaction(function () use ($types) {
            foreach (self::APPS as $appCode => $screens) {
                [$name, $description] = self::NAMES[$appCode];
                $app = Meta::updateOrCreate(['code' => $appCode], [
                    'name' => $name,
                    'type_id' => $types['app'],
                    'description' => $description,
                    'is_active' => true,
                ]);
                MetaParam::where('main_meta_id', $app->id)->delete();
                foreach ($screens as $i => [$code, $label, $path, $icon, $about]) {
                    $screen = Meta::updateOrCreate(['code' => $code], [
                        'name' => $label,
                        'type_id' => $types['screen'],
                        'description' => $about,
                        'json_config' => ['path' => $path],
                        'is_active' => true,
                    ]);
                    MetaParam::create([
                        'main_meta_id' => $app->id,
                        'order_number' => $i + 1,
                        'field_path' => substr($code, 7),
                        'param_name' => substr($code, 7),
                        'label_name' => $label,
                        'data_type' => 'string',
                        'icon_cls' => $icon,
                        'ref_meta_id' => $screen->id,
                        'is_show' => true,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $codes = array_keys(self::APPS);
            foreach (self::APPS as $screens) {
                $codes = [...$codes, ...array_column($screens, 0)];
            }
            $ids = Meta::whereIn('code', $codes)->pluck('id');
            DB::table('meta_permissions')->whereIn('meta_id', $ids)->delete();
            MetaParam::whereIn('main_meta_id', $ids)->delete();
            Meta::whereIn('id', $ids)->delete();
        });
    }
};
