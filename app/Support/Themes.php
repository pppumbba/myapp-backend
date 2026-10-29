<?php

namespace App\Support;

use App\Models\Meta;
use App\Models\MetaType;
use Illuminate\Support\Facades\DB;

/**
 * Theme metas: json_config.theme says how the app looks. The frontend turns it into the
 * colour, corner and font tokens (see lib/theme.ts). One theme is the default for everyone;
 * a user may pick another, and an app may have its own, used while it is open.
 */
class Themes
{
    public const GRAYS = ['neutral', 'slate', 'zinc', 'stone', 'warm'];

    public const RADII = ['none', 'small', 'default', 'large', 'round'];

    public const FONTS = ['inter', 'system', 'serif', 'rounded'];

    public const DENSITIES = ['compact', 'default', 'comfortable'];

    /** any: the user's light or dark; light or dark: always that. */
    public const MODES = ['any', 'light', 'dark'];

    /** The app's own look, used when no theme says otherwise. */
    public const BASE = ['brand' => '#4f46e5', 'gray' => 'neutral', 'radius' => 'default', 'font' => 'inter', 'density' => 'default', 'mode' => 'any', 'default' => false, 'apps' => []];

    /**
     * Every active theme, the default one's id, and which app uses which theme.
     *
     * @return array{themes: list<array{id: int, code: string, name: string, theme: array}>, default_id: ?int, apps: array<int, int>}
     */
    public static function all(): array
    {
        $themes = Meta::where('type_id', MetaType::where('code', 'theme')->value('id'))->where('is_active', true)->orderBy('name')->get()
            ->map(fn (Meta $m) => ['id' => $m->id, 'code' => $m->code, 'name' => $m->name, 'theme' => self::of($m)])->values();
        $apps = [];
        foreach ($themes as $t) {
            foreach ($t['theme']['apps'] as $app) {
                $apps[$app] ??= $t['id'];
            }
        }

        return ['themes' => $themes->all(), 'default_id' => $themes->firstWhere('theme.default', true)['id'] ?? null, 'apps' => $apps];
    }

    /** A theme meta's settings, each filled in. */
    public static function of(Meta $meta): array
    {
        return self::normalize((array) ($meta->json_config['theme'] ?? []), false);
    }

    /**
     * Settings as stored: known values only, the rest the base's. With $strict, an unknown
     * value is refused (422) rather than replaced.
     */
    public static function normalize(array $input, bool $strict = true): array
    {
        $theme = self::BASE;
        $pick = function (string $key, array $allowed) use ($input, $strict, &$theme) {
            if (! array_key_exists($key, $input) || $input[$key] === null) {
                return;
            }
            $ok = in_array($input[$key], $allowed, true);
            abort_if($strict && ! $ok, 422, __('":value" isn\'t a :key a theme can have.', ['value' => $input[$key], 'key' => $key]));
            if ($ok) {
                $theme[$key] = $input[$key];
            }
        };
        $pick('gray', self::GRAYS);
        $pick('radius', self::RADII);
        $pick('font', self::FONTS);
        $pick('density', self::DENSITIES);
        $pick('mode', self::MODES);

        if (isset($input['brand'])) {
            $ok = is_string($input['brand']) && preg_match('/^#[0-9a-fA-F]{6}$/', $input['brand']);
            abort_if($strict && ! $ok, 422, 'The brand colour must be a colour like #4f46e5.');
            if ($ok) {
                $theme['brand'] = strtolower($input['brand']);
            }
        }
        $theme['default'] = (bool) ($input['default'] ?? false);
        $theme['apps'] = array_values(array_unique(array_map('intval', array_filter((array) ($input['apps'] ?? []), 'is_numeric'))));

        return $theme;
    }

    /**
     * Saves a theme meta's settings. Only one theme is the default, and an app has one theme:
     * making this one the default, or giving it an app, takes that from the others.
     */
    public static function save(Meta $meta, array $input, ?int $userId = null): array
    {
        $meta->loadMissing('type');
        abort_unless($meta->type?->code === 'theme', 422, 'Only theme metas have theme settings.');
        $theme = self::normalize($input);
        $appType = MetaType::where('code', 'app')->value('id');
        $notApps = array_diff($theme['apps'], Meta::whereIn('id', $theme['apps'])->where('type_id', $appType)->pluck('id')->all());
        abort_if($notApps !== [], 422, 'Only apps can use a theme.');

        DB::transaction(function () use ($meta, $theme, $userId) {
            $others = Meta::where('type_id', $meta->type_id)->whereKeyNot($meta->id)->get();
            foreach ($others as $other) {
                $t = (array) ($other->json_config['theme'] ?? []);
                $kept = array_values(array_diff((array) ($t['apps'] ?? []), $theme['apps']));
                if (($theme['default'] && ! empty($t['default'])) || $kept !== array_values((array) ($t['apps'] ?? []))) {
                    $other->update(['json_config' => ['theme' => ['default' => $theme['default'] ? false : ($t['default'] ?? false), 'apps' => $kept] + $t] + ($other->json_config ?? [])]);
                }
            }
            $meta->update(['json_config' => ['theme' => $theme] + ($meta->json_config ?? [])] + ($userId ? ['updated_by' => $userId] : []));
        });

        return $theme;
    }
}
