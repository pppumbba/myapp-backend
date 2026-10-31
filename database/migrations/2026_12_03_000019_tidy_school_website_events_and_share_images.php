<?php

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\Role;
use App\Models\User;
use App\Support\SiteChecks;
use App\Support\Sites;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tidies the Maple Hill School website (2026_12_03_000018):
 *  - the events list reads "Saturday 14 November 2026 · 09:30 to 12:00" and "Main hall · Everyone
 *    welcome", built in the query so a missing time or place leaves no stray separator;
 *  - the home, facilities and clubs pages share their photo as the image for shared links.
 * The changed pages are published again, stopping on any floor or reflex finding.
 */
return new class extends Migration
{
    private const OLD_QUERY = 'SELECT e.id, e.title, e.starts_on, e.time_text, e.place, e.audience FROM school_events e WHERE e.starts_on >= CURRENT_DATE';

    private const NEW_QUERY = "SELECT e.id, e.title, e.starts_on, e.time_text, e.place, e.audience, CONCAT_WS(' · ', DATE_FORMAT(e.starts_on, '%W %e %M %Y'), e.time_text) AS when_text, CONCAT_WS(' · ', e.place, e.audience) AS where_text FROM school_events e WHERE e.starts_on >= CURRENT_DATE";

    private const OLD_LINES = [
        ['template' => '{title}', 'style' => 'title'],
        ['template' => '{starts_on} · {time_text}', 'style' => 'text'],
        ['template' => '{place} · {audience}', 'style' => 'muted'],
    ];

    private const NEW_LINES = [
        ['template' => '{title}', 'style' => 'title'],
        ['template' => '{when_text}', 'style' => 'text'],
        ['template' => '{where_text}', 'style' => 'muted'],
    ];

    /** Pages that share their photo, by field_path => the photo widget's page meta code. */
    private const SHARE = ['home' => 'site_school_home', 'facilities' => 'site_school_facilities', 'clubs' => 'site_school_clubs'];

    public function up(): void
    {
        $site = Meta::where('code', 'site_school')->first();
        if (! $site) {
            return;
        }
        DB::transaction(function () use ($site) {
            $this->apply($site, self::NEW_QUERY, self::NEW_LINES, true);
        });
    }

    public function down(): void
    {
        $site = Meta::where('code', 'site_school')->first();
        if (! $site) {
            return;
        }
        DB::transaction(function () use ($site) {
            $this->apply($site, self::OLD_QUERY, self::OLD_LINES, false);
        });
    }

    private function apply(Meta $site, string $query, array $lines, bool $share): void
    {
        $grid = Meta::where('code', 'school_events_upcoming')->firstOrFail();
        $grid->update(['query_string' => $query]);
        MetaParam::where('main_meta_id', $grid->id)->whereIn('field_path', ['when_text', 'where_text'])->delete();
        if ($share) {
            $next = (int) MetaParam::where('main_meta_id', $grid->id)->max('order_number');
            foreach ([['when_text', 'When'], ['where_text', 'Where and who']] as $i => [$field, $label]) {
                MetaParam::create(['main_meta_id' => $grid->id, 'order_number' => $next + $i + 1, 'field_path' => $field, 'param_name' => $field, 'label_name' => $label, 'data_type' => 'string']);
            }
        }

        $records = Meta::where('code', 'like', 'site\_school\_events\_records\_%')->firstOrFail();
        $records->update(['json_config' => ['lines' => $lines] + $records->json_config]);

        $entries = Sites::entries($site)->keyBy('field_path');
        foreach (self::SHARE as $path => $pageCode) {
            $entry = $entries[$path];
            $photo = Meta::where('code', 'like', $pageCode.'\_photo\_%')->value('json_config');
            $config = $entry->json_config;
            if ($share && ! empty($photo['src'])) {
                $config['image'] = $photo['src'];
            } else {
                unset($config['image']);
            }
            $entry->update(['json_config' => $config]);
        }

        $admin = User::whereHas('roles', fn ($q) => $q->where('code', Role::ADMIN))->orderBy('id')->first();
        if (! $admin) {
            return;
        }
        foreach (['events', ...array_keys(self::SHARE)] as $path) {
            $entry = $entries[$path]->refresh();
            $issues = SiteChecks::page($site, $entry, Sites::snapshot($entry, $admin));
            $blocking = array_filter($issues, fn ($i) => $i['level'] !== 'finish');
            if ($blocking !== []) {
                throw new RuntimeException("Page {$path} fails the site checks: ".json_encode(array_values($blocking)));
            }
            Sites::publish($site, $entry, $admin, null, null);
        }
    }
};
