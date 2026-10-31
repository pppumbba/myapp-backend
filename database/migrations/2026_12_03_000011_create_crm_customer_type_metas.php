<?php

use App\Models\Meta;
use App\Models\MetaAction;
use App\Models\MetaParam;
use App\Models\MetaPermission;
use App\Models\Role;
use App\Models\Tag;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer types in the CRM (tables: 2026_12_03_000010):
 *
 *   - Customer types (crm_customer_types_grid / crm_customer_type_form), under CRM Setup.
 *   - The customer form gets a Types detail (crm_customer_type_dtl_form): one customer can be
 *     a customer, supplier, transporter, employer... at once; at least one, each once. Its
 *     "Also a supplier" box goes: a type with is_supplier sets it (triggers).
 *   - The customer registry reads through a query that adds the customer's types, as a filter
 *     (contains) and a column; "Customers by type" tabs keep to each type.
 *   - Make supplier also gives the customer the supplier type; a lead converted into a customer
 *     gets the Customer type.
 *
 * Idempotent: metas matched by code, rows added to existing metas matched by field_path.
 */
return new class extends Migration
{
    /** @var array<string, int> */
    private array $types = [];

    /** @var array<string, Meta> existing CRM metas */
    private array $x = [];

    /** @var array<string, Meta> metas made here */
    private array $m = [];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('crm_customer_type_dtl')) {
            return;
        }
        $this->types = DB::table('meta_types')->pluck('id', 'code')->all();
        $this->x = Meta::where('code', 'like', 'crm\_%')->get()->keyBy('code')->all();
        foreach (['crm_customer_form', 'crm_customers_grid', 'crm_setup', 'crm_app', 'crm_make_supplier', 'crm_finish_lead_conversion'] as $code) {
            if (! isset($this->x[$code])) {
                return;
            }
        }

        DB::transaction(function () {
            $this->typeScreens();
            $this->customerForm();
            $this->registry();
            $this->flows();
            $this->access();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        DB::transaction(function () {
            $ids = DB::table('metas')->whereIn('code', ['crm_customer_type_form', 'crm_customer_types_grid', 'crm_customer_type_dtl_form', 'crm_customers_by_type_tabs'])->pluck('id');
            DB::table('meta_params')->whereIn('ref_meta_id', $ids)->delete();
            DB::table('metas')->whereIn('id', $ids)->delete();
            DB::table('metas')->where('code', 'crm_customers_grid')->update(['query_string' => null]);
            DB::table('meta_params')->where('main_meta_id', DB::table('metas')->where('code', 'crm_customers_grid')->value('id'))->where('field_path', 'types')->delete();
        });
    }

    private function typeScreens(): void
    {
        $form = $this->meta('crm_customer_type_form', 'Customer type form', 'form', 'crm_customer_types', 'A kind of relationship with a customer: customer, supplier, transporter, employer... "Supplier" types link the customer to Procurement.');
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_required' => true, 'is_unique' => true]],
            ['name', 'Name', 'string', ['is_required' => true, 'is_unique' => true, 'is_label' => true]],
            ['is_supplier', 'Is a supplier (Procurement)', 'boolean', ['default_value' => '0']],
            ['is_active', 'Active', 'boolean', ['default_value' => '1']],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
        ]);
        $grid = $this->meta('crm_customer_types_grid', 'Customer types', 'datagrid', 'crm_customer_types', 'The types a customer can have, several at once.');
        $this->actions($grid, $form);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_criteria' => true]],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['is_supplier', 'Supplier', 'boolean', ['is_criteria' => true]],
            ['is_active', 'Status', 'boolean', ['is_criteria' => true, 'render_type' => 'active']],
            ['notes', 'Notes', 'text'],
        ]);
        $this->row($this->x['crm_setup'], null, 'customer_types', 'Customer types', $grid);

        $line = $this->meta('crm_customer_type_dtl_form', 'Customer type (line)', 'form', 'crm_customer_type_dtl', 'One type of a customer, since when.');
        $this->params($line, [
            ['id', 'ID', 'integer', $this->key()],
            ['customer_id', 'Customer', 'integer', ['is_show' => false]],
            ['customer_type_id', 'Type', 'integer', ['is_required' => true, 'is_select' => true, 'ref_meta_id' => $grid->id, 'render_type' => 'lookup', 'display_field' => 'name']],
            ['since', 'Since', 'date', ['default_value' => '{today}']],
            ['notes', 'Note', 'string'],
        ]);
    }

    private function customerForm(): void
    {
        $form = $this->x['crm_customer_form'];
        $form->update([
            'description' => 'A customer: company or person, with its types (customer, supplier, transporter, employer...), contacts, addresses and files. Every change is logged.',
            'before_save_expression_string' => <<<'JS'
                const types = lines('types').map((l) => String(l.customer_type_id || ''));
                if (types.filter((t) => t !== '').length === 0) {
                  error('Give the customer at least one type.');
                }
                if (new Set(types).size !== types.length) {
                  error('Each type can be given only once.');
                }
                JS,
        ]);
        $this->m['crm_customer_form'] = $form;

        // "Also a supplier" is set by the types now.
        MetaParam::where('main_meta_id', $form->id)->where('field_path', 'is_supplier')->delete();
        if (! MetaParam::where('main_meta_id', $form->id)->where('field_path', 'types')->exists()) {
            $at = (int) (MetaParam::where('main_meta_id', $form->id)->where('field_path', 'contacts')->value('order_number') ?? 99);
            MetaParam::where('main_meta_id', $form->id)->whereNull('parent_id')->where('order_number', '>=', $at)->increment('order_number');
            MetaParam::create([
                'main_meta_id' => $form->id, 'order_number' => $at, 'field_path' => 'types', 'param_name' => 'types',
                'label_name' => 'Types', 'data_type' => 'detail', 'ref_meta_id' => $this->m['crm_customer_type_dtl_form']->id,
                'json_config' => ['foreign_key' => 'customer_id'], 'is_show' => true,
            ]);
        }
    }

    private function registry(): void
    {
        $grid = $this->x['crm_customers_grid'];
        // Still crm_customers (lookups name customers from it); the query adds the types.
        $grid->update(['query_string' => <<<'SQL'
            SELECT c.id, c.code, c.name, c.kind, c.register_no, c.category_id, c.owner_id, c.phone, c.email,
                   c.is_supplier, c.supplier_id, c.status,
                   (SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ', ')
                    FROM crm_customer_type_dtl d JOIN crm_customer_types t ON t.id = d.customer_type_id
                    WHERE d.customer_id = c.id) AS types
            FROM crm_customers c
            SQL]);
        $this->m['crm_customers_grid'] = $grid;
        $existing = MetaParam::where('main_meta_id', $grid->id)->where('field_path', 'types')->first();
        $values = ['label_name' => 'Types', 'data_type' => 'string', 'is_criteria' => true, 'is_show' => true];
        if ($existing) {
            $existing->update($values);
        } else {
            $at = (int) (MetaParam::where('main_meta_id', $grid->id)->where('field_path', 'kind')->value('order_number') ?? 3) + 1;
            MetaParam::where('main_meta_id', $grid->id)->whereNull('parent_id')->where('order_number', '>=', $at)->increment('order_number');
            MetaParam::create($values + ['main_meta_id' => $grid->id, 'order_number' => $at, 'field_path' => 'types', 'param_name' => 'types']);
        }

        $tab = fn (string $field, string $label, array $filters) => [$field, $label, 'string', ['ref_meta_id' => $grid->id, 'json_config' => $filters ? ['filters' => $filters] : null]];
        $rows = [$tab('all', 'All', [])];
        foreach (DB::table('crm_customer_types')->where('is_active', true)->orderBy('id')->get(['code', 'name']) as $type) {
            $rows[] = $tab($type->code, $type->name, ['types' => $type->name]);
        }
        $tabs = $this->meta('crm_customers_by_type_tabs', 'Customers by type', 'tabs', null, 'Customers by their types: customers, suppliers, transporters, employers...');
        $this->params($tabs, $rows);

        $customers = MetaParam::where('main_meta_id', $this->x['crm_app']->id)->whereNull('parent_id')->where('field_path', 'customers')->first();
        if ($customers) {
            $this->row($this->x['crm_app'], $customers->id, 'by_type', 'Customers by type', $tabs);
        }
    }

    private function flows(): void
    {
        // Reads only crm_customer_types, so the type triggers may update crm_customers.
        $giveType = fn (string $where) => <<<SQL
            INSERT IGNORE INTO crm_customer_type_dtl (customer_id, customer_type_id, since, created_at, updated_at)
            SELECT :customer_id, t.id, CURRENT_DATE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            FROM crm_customer_types t
            WHERE {$where}
            SQL;

        $make = $this->x['crm_make_supplier'];
        $sql = rtrim((string) $make->query_string, " ;\n");
        if (! str_contains($sql, 'crm_customer_type_dtl')) {
            $make->update([
                'query_string' => $sql.";\n".$giveType('t.id = (SELECT MIN(s.id) FROM crm_customer_types s WHERE s.is_supplier = 1)'),
                'description' => 'Adds the customer to Procurement’s suppliers (once), links them and gives the customer the supplier type, so purchase orders can be placed with it.',
            ]);
        }
        $this->m['crm_make_supplier'] = $make;

        $finish = $this->x['crm_finish_lead_conversion'];
        $sql = rtrim((string) $finish->query_string, " ;\n");
        if (! str_contains($sql, 'crm_customer_type_dtl')) {
            $finish->update(['query_string' => $sql.";\n".$giveType("t.code = 'customer'")]);
        }
        $this->m['crm_finish_lead_conversion'] = $finish;
    }

    private function access(): void
    {
        $role = Role::where('code', 'crm')->first();
        $tag = Tag::firstOrCreate(['name' => 'crm'], ['color' => 'blue']);
        $types = array_flip($this->types);
        $now = now();
        foreach ($this->m as $meta) {
            $type = $types[$meta->type_id] ?? null;
            if ($role) {
                $full = $type === 'form';
                MetaPermission::updateOrCreate(['meta_id' => $meta->id, 'role_id' => $role->id], [
                    'can_view' => true, 'can_create' => $full, 'can_edit' => $full || $type === 'stage_query', 'can_delete' => $full,
                ]);
            }
            if (! DB::table('meta_tag_map')->where('tag_id', $tag->id)->where('meta_id', $meta->id)->exists()) {
                DB::table('meta_tag_map')->insert(['meta_id' => $meta->id, 'tag_id' => $tag->id, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        // The contract app's party picker shows the registry; its role sees the types list too.
        $contracts = Role::where('code', 'contracts')->first();
        if ($contracts) {
            MetaPermission::firstOrCreate(['meta_id' => $this->m['crm_customer_types_grid']->id, 'role_id' => $contracts->id], ['can_view' => true, 'can_create' => false, 'can_edit' => false, 'can_delete' => false]);
        }
    }

    // ---------------------------------------------------------------- helpers (as in 2026_12_03_000001)

    private function meta(string $code, string $name, string $type, ?string $table, string $description): Meta
    {
        return $this->m[$code] = Meta::updateOrCreate(['code' => $code], [
            'name' => $name, 'type_id' => $this->types[$type], 'table_name' => $table, 'description' => $description,
            'ref_meta_id' => null, 'json_config' => null, 'query_string' => null, 'is_active' => true,
        ]);
    }

    private function params(Meta $meta, array $rows): void
    {
        MetaParam::where('main_meta_id', $meta->id)->delete();
        foreach ($rows as $i => $row) {
            [$field, $label, $type] = $row;
            MetaParam::create(($row[3] ?? []) + [
                'main_meta_id' => $meta->id, 'order_number' => $i + 1, 'field_path' => $field, 'param_name' => $field,
                'label_name' => $label, 'data_type' => $type, 'is_show' => true,
            ]);
        }
    }

    private function key(): array
    {
        return ['is_primary' => true, 'is_show' => false];
    }

    private function actions(Meta $grid, Meta $form): void
    {
        MetaAction::where('grid_meta_id', $grid->id)->delete();
        foreach ([['add', 'Add', 'plus', false], ['edit', 'Edit', 'edit', true], ['delete', 'Delete', 'trash', true]] as $i => [$action, $label, $icon, $needsRow]) {
            MetaAction::create([
                'grid_meta_id' => $grid->id, 'action' => $action, 'label' => $label, 'icon_cls' => $icon, 'form_meta_id' => $form->id,
                'needs_row' => $needsRow, 'show_in_row' => $needsRow, 'order_number' => $i + 1,
                'json_config' => $needsRow ? ['params' => ['id' => 'id']] : null,
            ]);
        }
    }

    private function row(Meta $meta, ?int $parentId, string $field, string $label, Meta $target): void
    {
        $scope = fn ($q) => $q->where('main_meta_id', $meta->id)->when($parentId === null, fn ($q) => $q->whereNull('parent_id'), fn ($q) => $q->where('parent_id', $parentId));
        $existing = MetaParam::where($scope)->where('field_path', $field)->first();
        if ($existing) {
            $existing->update(['label_name' => $label, 'ref_meta_id' => $target->id]);

            return;
        }
        MetaParam::create([
            'main_meta_id' => $meta->id, 'parent_id' => $parentId, 'order_number' => (int) MetaParam::where($scope)->max('order_number') + 1,
            'field_path' => $field, 'param_name' => $field, 'label_name' => $label, 'data_type' => 'string', 'ref_meta_id' => $target->id, 'is_show' => true,
        ]);
    }
};
