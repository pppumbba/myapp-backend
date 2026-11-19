<?php

namespace App\Support;

use App\Models\Meta;
use App\Models\MetaType;

/**
 * Menu style metas: json_config.menu says how an app's side menu looks (see lib/menuStyle.ts).
 * An app picks one with json_config.menu_style_meta_id; without one it uses its built-in
 * json_config.menu_style.
 */
class MenuStyles
{
    /** surface, subtle, brand and dark follow the theme; gradient uses the gradient setting; a #hex colour is fixed. */
    public const BACKGROUNDS = ['surface', 'subtle', 'brand', 'dark', 'gradient'];

    /** Degrees a gradient may run at. */
    public const ANGLES = [0, 45, 90, 135, 180];

    public const EDGES = ['line', 'shadow', 'none'];

    public const RADII = ['none', 'sm', 'md', 'lg'];

    /** Px between the menu and the page edge. */
    public const GAPS = [0, 8, 12, 16];

    public const WIDTHS = ['narrow', 'default', 'wide'];

    public const ITEMS = ['pill', 'bar', 'plain'];

    /** The Classic menu. */
    public const BASE = [
        'background' => 'surface', 'edge' => 'line', 'radius' => 'none', 'gap' => 0, 'width' => 'default', 'item' => 'pill', 'frame' => false,
        'gradient' => ['from' => 'brand', 'to' => '#ec4899', 'angle' => 135], 'glass' => false, 'backdrop' => null,
    ];

    /**
     * Every active menu style, by name.
     *
     * @return list<array{id: int, code: string, name: string, menu: array}>
     */
    public static function all(): array
    {
        return Meta::where('type_id', MetaType::where('code', 'menu_style')->value('id'))->where('is_active', true)->orderBy('name')->get()
            ->map(fn (Meta $m) => ['id' => $m->id, 'code' => $m->code, 'name' => $m->name, 'menu' => self::of($m)])->values()->all();
    }

    /** A menu style meta's settings, each filled in. */
    public static function of(Meta $meta): array
    {
        return self::normalize((array) ($meta->json_config['menu'] ?? []), false);
    }

    /**
     * Settings as stored: known values only, the rest the base's. With $strict, an unknown
     * value is refused (422) rather than replaced.
     */
    public static function normalize(array $input, bool $strict = true): array
    {
        $menu = self::BASE;
        $pick = function (string $key, array $allowed, mixed $value) use ($input, $strict, &$menu) {
            if (! array_key_exists($key, $input) || $input[$key] === null) {
                return;
            }
            $ok = in_array($value, $allowed, true);
            abort_if($strict && ! $ok, 422, __('":value" isn\'t a :key a menu style can have.', ['value' => (string) json_encode($input[$key]), 'key' => $key]));
            if ($ok) {
                $menu[$key] = $value;
            }
        };
        $pick('edge', self::EDGES, $input['edge'] ?? null);
        $pick('radius', self::RADII, $input['radius'] ?? null);
        $pick('gap', self::GAPS, is_numeric($input['gap'] ?? null) ? (int) $input['gap'] : null);
        $pick('width', self::WIDTHS, $input['width'] ?? null);
        $pick('item', self::ITEMS, $input['item'] ?? null);

        if (isset($input['background'])) {
            $value = $input['background'];
            $hex = is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value);
            $ok = $hex || in_array($value, self::BACKGROUNDS, true);
            abort_if($strict && ! $ok, 422, __('The menu background must be surface, subtle, brand, dark or a colour like #1e293b.'));
            if ($ok) {
                $menu['background'] = $hex ? strtolower($value) : $value;
            }
        }
        $menu['frame'] = (bool) ($input['frame'] ?? false);
        $menu['glass'] = (bool) ($input['glass'] ?? false);
        if (isset($input['gradient'])) {
            $menu['gradient'] = self::gradient($input['gradient'], 'gradient', $strict) ?? $menu['gradient'];
        }
        $menu['backdrop'] = isset($input['backdrop']) ? self::gradient($input['backdrop'], 'backdrop', $strict) : null;

        return $menu;
    }

    /**
     * A gradient {from, to, angle}: each end brand or a #hex colour, an angle from ANGLES. Null when
     * it isn't one and $strict is off; refused (422) when $strict is on.
     *
     * @return array{from: string, to: string, angle: int}|null
     */
    public static function gradient(mixed $input, string $key, bool $strict): ?array
    {
        $end = fn (mixed $v) => $v === 'brand' ? 'brand' : (is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : null);
        $from = $end($input['from'] ?? null);
        $to = $end($input['to'] ?? null);
        $angle = is_numeric($input['angle'] ?? null) ? (int) $input['angle'] : null;
        $ok = is_array($input) && $from && $to && in_array($angle, self::ANGLES, true);
        abort_if($strict && ! $ok, 422, __('The :key needs two colours (brand or like #1e293b) and an angle of 0, 45, 90, 135 or 180.', ['key' => $key]));

        return $ok ? ['from' => $from, 'to' => $to, 'angle' => $angle] : null;
    }

    /** Saves a menu style meta's settings. */
    public static function save(Meta $meta, array $input, ?int $userId = null): array
    {
        $meta->loadMissing('type');
        abort_unless($meta->type?->code === 'menu_style', 422, __('Only menu style metas have menu settings.'));
        $menu = self::normalize($input);
        $meta->update(['json_config' => ['menu' => $menu] + ($meta->json_config ?? [])] + ($userId ? ['updated_by' => $userId] : []));

        return $menu;
    }
}
