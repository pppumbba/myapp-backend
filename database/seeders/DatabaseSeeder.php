<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([MetaTypeSeeder::class, LanguageSeeder::class, ThemeSeeder::class, WidgetSeeder::class, ProcurementDemoSeeder::class, CalendarDemoSeeder::class, GanttDemoSeeder::class, LmsDemoSeeder::class, TenderDemoSeeder::class, EamDemoSeeder::class]);

        // User::factory(10)->create();

        User::factory()->admin()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // Published as the administrator above.
        $this->call(WebsiteDemoSeeder::class);
        $this->call(ClimbingDemoSeeder::class);
    }
}
