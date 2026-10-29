<?php

namespace Database\Seeders;

use App\Models\Language;
use App\Support\Translations;
use Illuminate\Database\Seeder;

/** English (labels are written in it, and the default) and Mongolian. Safe to run again: changes are kept. */
class LanguageSeeder extends Seeder
{
    public function run(): void
    {
        $hasSource = Language::where('is_source', true)->exists();
        $hasDefault = Language::where('is_default', true)->exists();
        foreach ([['en', 'English', 1], ['mn', 'Монгол', 2]] as [$code, $name, $order]) {
            Language::firstOrCreate(['code' => $code], [
                'name' => $name,
                'order_number' => $order,
                'is_source' => $code === 'en' && ! $hasSource,
                'is_default' => $code === 'en' && ! $hasDefault,
            ]);
        }
        Translations::forgetLanguages();
    }
}
