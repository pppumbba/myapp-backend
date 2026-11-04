<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerDomain;
use App\Models\Meta;
use App\Models\MetaType;
use App\Models\User;
use App\Support\CustomerSchema;
use App\Support\Sites;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Guests have no token: the customer comes from the address (code in the path, or a domain). */
class PublicCustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_public_meta_opens_under_its_customer_code(): void
    {
        $other = Customer::create(['code' => 'other', 'name' => 'Other', 'schema_name' => 'cust_other']);
        $meta = $this->publicMeta();

        $this->getJson("/api/c/other/metas/{$meta->id}")->assertOk()->assertJsonPath('id', $meta->id);
        $this->assertSame($other->id, CustomerSchema::current()->id);

        $this->getJson("/api/c/nobody/metas/{$meta->id}")->assertNotFound();
    }

    public function test_a_signed_in_person_opening_another_customers_link_sees_that_customer(): void
    {
        $other = Customer::create(['code' => 'other', 'name' => 'Other', 'schema_name' => 'cust_other']);
        $meta = $this->publicMeta();
        $user = User::factory()->create();
        $token = $user->createToken('t');
        $token->accessToken->forceFill(['customer_id' => $this->testCustomer->id])->save();

        $this->withToken($token->plainTextToken)->getJson("/api/c/other/metas/{$meta->id}")->assertOk();
        $this->assertSame($other->id, CustomerSchema::current()->id);
        $this->assertNull(auth('sanctum')->user());
    }

    public function test_old_public_links_use_the_first_customer(): void
    {
        $first = Customer::create(['code' => config('customers.first_code'), 'name' => 'First', 'schema_name' => 'cust_first']);
        $meta = $this->publicMeta();

        $this->getJson("/api/metas/{$meta->id}")->assertOk();
        $this->assertSame($first->id, CustomerSchema::current()->id);
    }

    public function test_a_website_domain_finds_its_customer(): void
    {
        $other = Customer::create(['code' => 'other', 'name' => 'Other', 'schema_name' => 'cust_other']);
        CustomerDomain::create(['domain' => 'shop.example.com', 'customer_id' => $other->id]);

        $this->getJson('/api/site?host=shop.example.com');
        $this->assertSame($other->id, CustomerSchema::current()->id);
    }

    public function test_a_domain_belongs_to_one_customer(): void
    {
        $other = Customer::create(['code' => 'other', 'name' => 'Other', 'schema_name' => 'cust_other']);
        CustomerDomain::create(['domain' => 'shop.example.com', 'customer_id' => $other->id]);

        $this->expectExceptionMessage('This domain is used by another customer.');
        Sites::claimDomains(['shop.example.com']);
    }

    public function test_a_domain_is_claimed_for_the_current_customer(): void
    {
        Sites::claimDomains(['www.mine.mn']);

        $this->assertSame($this->testCustomer->id, CustomerDomain::where('domain', 'www.mine.mn')->value('customer_id'));
    }

    private function publicMeta(): Meta
    {
        $typeId = MetaType::where('code', 'datagrid')->value('id') ?? DB::table('meta_types')->insertGetId(['code' => 'datagrid', 'name' => 'Data grid']);

        return Meta::create(['code' => 'pub_'.uniqid(), 'name' => 'Public grid', 'type_id' => $typeId, 'is_active' => true, 'is_public' => true]);
    }
}
