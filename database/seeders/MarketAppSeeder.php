<?php

namespace Database\Seeders;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use Database\Seeders\Concerns\SeedsActions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The tender market as a meta app. Its pages are built into the system (the Market module), so
 * each one is a screen meta naming its address; the app's menu points at them like at any meta.
 *
 *   Tender market (app, open to every signed-in user)
 *   ├─ Account       -> screen /market/account   (DAN, companies, members)
 *   ├─ Contracts     -> screen /market/contracts
 *   ├─ Payments      -> screen /market/payments
 *   └─ Back office   (admins only: these screens have no permission rows)
 *      ├─ Representation reviews -> /admin/market-reviews
 *      ├─ Disputes               -> /admin/market-disputes
 *      ├─ Fees                   -> /admin/market-fees
 *      └─ Categories             -> datagrid + form metas over market_categories (spec templates as lines)
 *
 * Safe to run again: metas are matched by code and the menu rebuilt.
 */
class MarketAppSeeder extends Seeder
{
    use SeedsActions;

    public function run(): void
    {
        $this->call(MetaTypeSeeder::class);
        $types = MetaType::pluck('id', 'code')->all();

        DB::transaction(function () use ($types) {
            $screen = fn (string $code, string $name, string $path, string $description, bool $open) => Meta::updateOrCreate(['code' => $code], [
                'name' => $name,
                'type_id' => $types['screen'],
                'description' => $description,
                'json_config' => ['path' => $path],
                'is_open' => $open,
                'is_active' => true,
            ]);

            $account = $screen('market_account', 'Market account', '/market/account', 'Verify with DAN, register companies, manage members.', true);
            $tenders = $screen('market_tenders', 'Tenders', '/market/tenders', 'Open tenders to bid on, as yourself or for your companies.', true);
            $myTenders = $screen('market_my_tenders', 'My tenders', '/market/tenders/mine', 'Tenders you posted, from draft to award.', true);
            $myBids = $screen('market_bids', 'My bids', '/market/bids', 'Bids you sent and where each tender stands.', true);
            $contracts = $screen('market_contracts', 'Contracts', '/market/contracts', 'Contracts you buy or sell under, signed with DAN.', true);
            $payments = $screen('market_payments', 'Payments', '/market/payments', 'Fees and payments held by the market, with receipts.', true);
            $reviews = $screen('market_reviews', 'Representation reviews', '/admin/market-reviews', 'Powers of attorney waiting for staff.', false);
            $disputes = $screen('market_disputes', 'Disputes', '/admin/market-disputes', 'Disputes on contracts and the money they freeze.', false);
            $fees = $screen('market_fees', 'Fees', '/admin/market-fees', 'What the market charges.', false);

            $app = Meta::updateOrCreate(['code' => 'market_app'], [
                'name' => 'Tender market',
                'type_id' => $types['app'],
                'description' => 'Tenders, contracts, payments and verified companies.',
                'is_open' => true,
                'is_active' => true,
            ]);

            MetaParam::where('main_meta_id', $app->id)->delete();
            $item = fn (int $order, string $field, string $label, string $icon, ?Meta $opens, ?int $parent = null) => MetaParam::create([
                'main_meta_id' => $app->id,
                'parent_id' => $parent,
                'order_number' => $order,
                'field_path' => $field,
                'param_name' => $field,
                'label_name' => $label,
                'data_type' => 'string',
                'icon_cls' => $icon,
                'ref_meta_id' => $opens?->id,
                'is_show' => true,
            ]);
            $item(1, 'tenders', 'Tenders', 'search', $tenders);
            $item(2, 'my_tenders', 'My tenders', 'folder', $myTenders);
            $item(3, 'my_bids', 'My bids', 'package', $myBids);
            $item(4, 'contracts', 'Contracts', 'file', $contracts);
            $item(5, 'payments', 'Payments', 'receipt', $payments);
            $item(6, 'account', 'Account', 'users', $account);
            $office = $item(7, 'back_office', 'Back office', 'settings', null);
            $item(1, 'reviews', 'Representation reviews', 'search', $reviews, $office->id);
            $item(2, 'disputes', 'Disputes', 'tool', $disputes, $office->id);
            $item(3, 'fees', 'Fees', 'chart', $fees, $office->id);
            $item(4, 'categories', 'Categories', 'folder', $this->categoryMetas($types), $office->id);
        });
    }

    /**
     * Categories and their spec templates, edited by staff through a grid and a form whose lines
     * are the template. No permission rows, so admins only.
     *
     * @param  array<string, int>  $types
     */
    private function categoryMetas(array $types): Meta
    {
        $meta = fn (string $code, string $name, string $type, string $table, string $description) => Meta::updateOrCreate(['code' => $code], [
            'name' => $name,
            'type_id' => $types[$type],
            'table_name' => $table,
            'description' => $description,
            'is_active' => true,
        ]);

        $grid = $meta('market_categories_grid', 'Categories', 'datagrid', 'market_categories', 'Tender and listing categories, each with the specs new items start from.');
        $specForm = $meta('market_category_spec_form', 'Category spec', 'form', 'market_category_specs', 'One line of a category’s spec template.');
        $form = $meta('market_category_form', 'Category', 'form', 'market_categories', 'A category and its spec template.');

        $this->params($specForm, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['category_id', 'Category', 'integer', ['is_show' => false]],
            ['position', 'Order', 'integer', ['default_value' => '1']],
            ['label', 'Spec', 'string', ['is_required' => true]],
            ['kind', 'Kind', 'string', ['is_required' => true, 'render_type' => 'select', 'default_value' => 'text', 'json_config' => ['options' => ['text', 'number']]]],
            ['operator', 'Compare', 'string', ['render_type' => 'select', 'json_config' => ['options' => ['gte', 'lte', 'eq']]]],
            ['value', 'Value', 'string'],
            ['unit', 'Unit', 'string'],
            ['must', 'Must-have', 'boolean', ['default_value' => '0']],
        ]);
        $this->params($form, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['name', 'Name', 'string', ['is_required' => true, 'is_label' => true]],
            ['parent_id', 'Inside', 'integer', ['is_select' => true, 'ref_meta_id' => $grid->id, 'render_type' => 'lookup', 'display_field' => 'name']],
            ['is_active', 'Active', 'boolean', ['default_value' => '1']],
            ['specs', 'Spec template', 'detail', ['ref_meta_id' => $specForm->id, 'json_config' => ['foreign_key' => 'category_id']]],
        ]);
        $this->params($grid, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['is_active', 'Status', 'boolean', ['render_type' => 'active']],
        ]);
        $this->actions($grid, $form);

        return $grid;
    }

    /** Replaces a meta's params. Each row: [field_path, label, data_type, extra attributes]. */
    private function params(Meta $meta, array $rows): void
    {
        MetaParam::where('main_meta_id', $meta->id)->delete();
        foreach ($rows as $i => $row) {
            [$field, $label, $type] = $row;
            MetaParam::create(($row[3] ?? []) + [
                'main_meta_id' => $meta->id,
                'order_number' => $i + 1,
                'field_path' => $field,
                'param_name' => $field,
                'label_name' => $label,
                'data_type' => $type,
                'is_show' => true,
            ]);
        }
    }
}
