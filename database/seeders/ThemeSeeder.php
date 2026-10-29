<?php

namespace Database\Seeders;

use App\Models\Meta;
use App\Models\MetaType;
use Illuminate\Database\Seeder;

/** Starter themes to pick from. "Indigo" is the app's own look and the default. Safe to run again: settings already changed are kept. */
class ThemeSeeder extends Seeder
{
    public function run(): void
    {
        $type = MetaType::where('code', 'theme')->value('id');
        $themes = [
            'theme_indigo' => ['Indigo', 'The standard look.', ['brand' => '#4f46e5', 'default' => true]],
            'theme_ocean' => ['Ocean', 'Blue on cool slate greys, rounder corners.', ['brand' => '#0284c7', 'gray' => 'slate', 'radius' => 'large']],
            'theme_forest' => ['Forest', 'Green on warm stone greys.', ['brand' => '#15803d', 'gray' => 'stone']],
            'theme_sunset' => ['Sunset', 'Orange, warm greys and round corners.', ['brand' => '#ea580c', 'gray' => 'warm', 'radius' => 'round', 'font' => 'rounded']],
            'theme_ledger' => ['Ledger', 'Compact and square, for long lists of numbers.', ['brand' => '#334155', 'gray' => 'zinc', 'radius' => 'small', 'density' => 'compact', 'font' => 'system']],
            'theme_midnight' => ['Midnight', 'Always dark, violet.', ['brand' => '#8b5cf6', 'gray' => 'zinc', 'mode' => 'dark']],
        ];
        foreach ($themes as $code => [$name, $description, $theme]) {
            $meta = Meta::firstOrNew(['code' => $code]);
            if (! $meta->exists) {
                $meta->fill(['name' => $name, 'type_id' => $type, 'description' => $description, 'json_config' => ['theme' => $theme], 'is_active' => true])->save();
            }
        }
    }
}
