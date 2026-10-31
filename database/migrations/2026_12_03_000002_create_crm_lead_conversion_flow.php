<?php

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaPermission;
use App\Models\MetaProcessBehaviour;
use App\Models\MetaProcessTransition;
use App\Models\MetaProcessWorkflow;
use App\Models\Role;
use App\Models\Tag;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The CRM lead conversion (needs 2026_12_03_000001_create_crm_metas). Saving the "Convert
 * lead" form runs, in one transaction:
 *
 *   1. the customer form   name, kind, category, owner, email, phone from the conversion
 *   2. the opportunity form for that customer, at qualification, with the lead's value
 *   3. crm_finish_lead_conversion (stage query): the lead becomes Converted and points at the
 *      customer and opportunity, the contact person becomes the customer's main contact, and
 *      the lead's activities move to the customer
 *
 * Idempotent: the stage query is matched by code and the form's process flow is rebuilt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        $m = Meta::whereIn('code', ['crm_lead_conversion_form', 'crm_customer_form', 'crm_opportunity_form', 'crm_lead_workflow'])->get()->keyBy('code');
        if ($m->count() < 4) {
            return;
        }
        $converted = DB::table('wfm_status')->where('workflow_meta_id', $m['crm_lead_workflow']->id)->where('code', 'converted')->value('id');

        DB::transaction(function () use ($m, $converted) {
            $finish = Meta::updateOrCreate(['code' => 'crm_finish_lead_conversion'], [
                'name' => 'Finish lead conversion',
                'type_id' => DB::table('meta_types')->where('code', 'stage_query')->value('id'),
                'table_name' => 'crm_leads',
                'description' => 'Marks a lead converted and links it to its new customer and opportunity; its contact becomes the main contact and its activities move to the customer.',
                'query_string' => strtr(<<<'SQL'
                    UPDATE crm_leads
                    SET status_id = {converted}, customer_id = :customer_id, opportunity_id = :opportunity_id, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :lead_id;
                    UPDATE crm_lead_conversions
                    SET customer_id = :customer_id, opportunity_id = :opportunity_id, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :conversion_id;
                    INSERT INTO crm_contacts (customer_id, name, email, phone, is_primary, created_at, updated_at)
                    SELECT :customer_id, :contact_name, :email, :phone, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                    FROM DUAL
                    WHERE :contact_name IS NOT NULL
                      AND NOT EXISTS (SELECT 1 FROM crm_contacts x WHERE x.customer_id = :customer_id);
                    UPDATE crm_activities SET customer_id = :customer_id, updated_at = CURRENT_TIMESTAMP
                    WHERE lead_id = :lead_id AND customer_id IS NULL
                    SQL, ['{converted}' => (string) $converted]),
                'json_config' => null,
                'is_active' => true,
            ]);
            MetaParam::where('main_meta_id', $finish->id)->delete();
            foreach ([
                ['lead_id', 'Lead', 'integer', true],
                ['customer_id', 'Customer', 'integer', true],
                ['opportunity_id', 'Opportunity', 'integer', true],
                ['conversion_id', 'Conversion', 'integer', false],
                ['contact_name', 'Contact person', 'string', false],
                ['email', 'Email', 'string', false],
                ['phone', 'Phone', 'string', false],
            ] as $i => [$field, $label, $type, $required]) {
                MetaParam::create([
                    'main_meta_id' => $finish->id, 'order_number' => $i + 1, 'field_path' => $field, 'param_name' => $field,
                    'label_name' => $label, 'data_type' => $type, 'is_required' => $required, 'is_show' => true,
                ]);
            }

            $form = $m['crm_lead_conversion_form'];
            MetaProcessTransition::where('main_process_id', $form->id)->delete();
            MetaProcessWorkflow::where('main_process_id', $form->id)->delete();
            $steps = [
                'customer' => [$m['crm_customer_form'], null, [
                    'name' => 'company_name', 'kind' => 'kind', 'category_id' => 'category_id', 'owner_id' => 'owner_id',
                    'email' => 'email', 'phone' => 'phone', 'status' => '=active',
                ]],
                'opportunity' => [$m['crm_opportunity_form'], 'customer', [
                    'customer_id' => 'id', 'name' => 'main.opportunity_name', 'amount' => 'main.amount',
                    'expected_close' => 'main.expected_close', 'owner_id' => 'main.owner_id', 'lead_id' => 'main.lead_id',
                    'stage' => '=qualification', 'probability' => '=25',
                ]],
                'finish' => [$finish, 'opportunity', [
                    'opportunity_id' => 'id', 'customer_id' => 'customer_id', 'lead_id' => 'main.lead_id', 'conversion_id' => 'main.id',
                    'contact_name' => 'main.contact_name', 'email' => 'main.email', 'phone' => 'main.phone',
                ]],
            ];
            $ids = [];
            $order = 0;
            foreach ($steps as $key => [$process, $after, $map]) {
                $ids[$key] = MetaProcessWorkflow::create(['main_process_id' => $form->id, 'process_id' => $process->id, 'order_number' => ++$order])->id;
                foreach ($map as $target => $source) {
                    MetaProcessBehaviour::create(['workflow_id' => $ids[$key], 'source_path' => $source, 'target_path' => $target]);
                }
                if ($after !== null) {
                    MetaProcessTransition::create(['main_process_id' => $form->id, 'source_id' => $ids[$after], 'target_id' => $ids[$key], 'criteria' => []]);
                }
            }

            $role = Role::where('code', 'crm')->first();
            if ($role) {
                MetaPermission::updateOrCreate(['meta_id' => $finish->id, 'role_id' => $role->id], ['can_view' => true, 'can_create' => false, 'can_edit' => true, 'can_delete' => false]);
            }
            $tag = Tag::firstOrCreate(['name' => 'crm'], ['color' => 'blue']);
            if (! DB::table('meta_tag_map')->where('tag_id', $tag->id)->where('meta_id', $finish->id)->exists()) {
                DB::table('meta_tag_map')->insert(['meta_id' => $finish->id, 'tag_id' => $tag->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        DB::transaction(function () {
            $form = DB::table('metas')->where('code', 'crm_lead_conversion_form')->value('id');
            if ($form) {
                DB::table('meta_process_transition')->where('main_process_id', $form)->delete();
                DB::table('meta_process_workflow')->where('main_process_id', $form)->delete();
            }
            DB::table('metas')->where('code', 'crm_finish_lead_conversion')->delete();
        });
    }
};
