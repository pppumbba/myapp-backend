<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaType;
use App\Models\User;
use Database\Seeders\MetaTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** A meta's type is set once: a save may keep it, never change it. */
class MetaTypeLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MetaTypeSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    private function typeId(string $code): int
    {
        return MetaType::where('code', $code)->value('id');
    }

    public function test_a_meta_with_a_type_cannot_be_given_another(): void
    {
        $meta = Meta::create(['code' => 'orders', 'name' => 'Orders', 'type_id' => $this->typeId('datagrid')]);

        $this->putJson("/api/metas/{$meta->id}", ['type_id' => $this->typeId('form')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type_id');
        $this->putJson("/api/metas/{$meta->id}", ['type_id' => null])->assertUnprocessable();

        $this->assertSame($this->typeId('datagrid'), $meta->fresh()->type_id);
    }

    public function test_a_save_may_keep_the_type_or_give_one_to_a_meta_without(): void
    {
        $meta = Meta::create(['code' => 'orders', 'name' => 'Orders', 'type_id' => $this->typeId('datagrid')]);
        $this->putJson("/api/metas/{$meta->id}", ['name' => 'All orders', 'type_id' => $this->typeId('datagrid')])->assertOk();
        $this->assertSame('All orders', $meta->fresh()->name);

        $untyped = Meta::create(['code' => 'loose', 'name' => 'Loose']);
        $this->putJson("/api/metas/{$untyped->id}", ['type_id' => $this->typeId('form')])->assertOk();
        $this->assertSame($this->typeId('form'), $untyped->fresh()->type_id);
    }
}
