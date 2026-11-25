<?php

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Website management: an app of its own for the websites, with pages that look across every
 * site (overview, releases and schedule, domains, form messages, visits). Websites moves here
 * from Platform management. Like the other built-in apps: no permission rows, so admins only.
 */
return new class extends Migration
{
    /** code => [label, path, icon, description] in menu order; screen_sites is there already. */
    private const SCREENS = [
        'screen_sites_overview' => ['Overview', '/sites/overview', 'home', 'Every website at a glance.'],
        'screen_sites' => ['Websites', '/sites', 'globe', 'Websites, their pages and releases.'],
        'screen_site_releases' => ['Releases & schedule', '/sites/releases', 'calendar', 'What went out on every site and what is about to.'],
        'screen_site_domains' => ['Domains', '/sites/domains', 'link', 'The addresses every site answers at.'],
        'screen_site_messages' => ['Form messages', '/sites/messages', 'mail', 'What visitors sent through the sites\' forms.'],
        'screen_site_visits' => ['Visits', '/sites/visits', 'chart', 'Page views across the sites.'],
    ];

    public function up(): void
    {
        $types = MetaType::pluck('id', 'code');
        if (! isset($types['app'], $types['screen'])) {
            return;
        }

        DB::transaction(function () use ($types) {
            $app = Meta::updateOrCreate(['code' => 'website_app'], [
                'name' => 'Website management',
                'type_id' => $types['app'],
                'description' => 'Websites, their releases, domains, form messages and visits.',
                'is_active' => true,
            ]);
            $order = 1;
            foreach (self::SCREENS as $code => [$label, $path, $icon, $description]) {
                $screen = Meta::updateOrCreate(['code' => $code], [
                    'name' => $label, 'type_id' => $types['screen'], 'description' => $description,
                    'json_config' => ['path' => $path], 'is_active' => true,
                ]);
                // Websites leaves whichever app held it (Platform management, or Meta studio before that).
                MetaParam::where('ref_meta_id', $screen->id)->where('main_meta_id', '!=', $app->id)
                    ->whereIn('main_meta_id', Meta::whereIn('code', ['platform_app', 'meta_studio_app'])->select('id'))->delete();
                MetaParam::updateOrCreate(['main_meta_id' => $app->id, 'ref_meta_id' => $screen->id], [
                    'order_number' => $order++, 'field_path' => substr($code, 7), 'param_name' => substr($code, 7),
                    'label_name' => $label, 'data_type' => 'string', 'icon_cls' => $icon, 'is_show' => true,
                ]);
            }
            // Platform management's menu closes the gap Websites left.
            $platform = Meta::where('code', 'platform_app')->first();
            if ($platform) {
                foreach (MetaParam::where('main_meta_id', $platform->id)->orderBy('order_number')->get()->values() as $i => $item) {
                    $item->update(['order_number' => $i + 1]);
                }
                $platform->update(['description' => 'Widgets, schedules, patches and session values.']);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $app = Meta::where('code', 'website_app')->first();
            $platform = Meta::where('code', 'platform_app')->first();
            $sites = Meta::where('code', 'screen_sites')->first();
            if ($platform && $sites) {
                MetaParam::create([
                    'main_meta_id' => $platform->id, 'order_number' => 2, 'field_path' => 'sites', 'param_name' => 'sites',
                    'label_name' => 'Websites', 'data_type' => 'string', 'icon_cls' => 'home', 'ref_meta_id' => $sites->id, 'is_show' => true,
                ]);
                $platform->update(['description' => 'Widgets, websites, schedules, patches and session values.']);
            }
            $screens = Meta::whereIn('code', array_diff(array_keys(self::SCREENS), ['screen_sites']))->pluck('id');
            MetaParam::whereIn('ref_meta_id', $screens)->delete();
            Meta::whereIn('id', $screens)->delete();
            if ($app) {
                MetaParam::where('main_meta_id', $app->id)->delete();
                DB::table('meta_permissions')->where('meta_id', $app->id)->delete();
                $app->delete();
            }
        });
    }
};
