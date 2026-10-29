<?php

namespace Database\Seeders;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use App\Support\QueryDesign;
use Illuminate\Database\Seeder;

/**
 * The Procurement app's query, drawn in the query builder (see QueryDesign):
 *
 *   Supplier spending   orders joined to their suppliers and supplier types, per supplier:
 *                       how many orders, their total and average, the last order date;
 *                       :from_date filters the orders, biggest spenders first
 *
 * and the grid made from it, with a menu item. Needs the Procurement demo
 * (ProcurementDemoSeeder calls this).
 */
class ProcurementQueriesSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(MetaTypeSeeder::class);
        $app = Meta::where('code', 'proc_app')->first();
        if (! $app) {
            $this->command?->warn('Run ProcurementDemoSeeder first: the query reads its orders.');

            return;
        }

        $query = Meta::updateOrCreate(['code' => 'proc_supplier_spending_query'], [
            'name' => 'Supplier spending',
            'type_id' => MetaType::where('code', 'query')->value('id'),
            'description' => 'Orders per supplier: how many, their total and average, and the last one.',
            'is_active' => true,
        ]);

        $col = fn (string $table, string $column) => ['table' => $table, 'column' => $column];
        QueryDesign::save($query, [
            'tables' => [
                ['key' => 'o', 'name' => 'proc_purchase_orders', 'x' => 24, 'y' => 24],
                ['key' => 's', 'name' => 'proc_suppliers', 'x' => 320, 'y' => 24, 'join' => ['type' => 'inner', 'on' => [['left' => $col('o', 'supplier_id'), 'op' => '=', 'right' => $col('s', 'id')]]]],
                ['key' => 'st', 'name' => 'proc_supplier_types', 'x' => 616, 'y' => 24, 'join' => ['type' => 'left', 'on' => [['left' => $col('s', 'supplier_type_id'), 'op' => '=', 'right' => $col('st', 'id')]]]],
            ],
            'columns' => [
                ['expr' => $col('s', 'id'), 'alias' => 'id', 'data_type' => 'integer', 'show' => true],
                ['expr' => $col('s', 'name'), 'alias' => 'supplier', 'label' => 'Supplier', 'show' => true],
                ['expr' => $col('st', 'name'), 'alias' => 'supplier_type', 'label' => 'Type', 'show' => true],
                ['expr' => $col('o', 'id'), 'alias' => 'orders', 'label' => 'Orders', 'data_type' => 'integer', 'aggregate' => 'count', 'show' => true],
                ['expr' => $col('o', 'total_amount'), 'alias' => 'total', 'label' => 'Total', 'data_type' => 'decimal', 'aggregate' => 'sum', 'show' => true, 'sort' => 'desc', 'sort_order' => 1],
                ['expr' => $col('o', 'total_amount'), 'alias' => 'average', 'label' => 'Average order', 'data_type' => 'decimal', 'aggregate' => 'avg', 'show' => true],
                ['expr' => $col('o', 'order_date'), 'alias' => 'last_order', 'label' => 'Last order', 'data_type' => 'date', 'aggregate' => 'max', 'show' => true],
            ],
            'where' => ['logic' => 'and', 'items' => [
                ['type' => 'condition', 'left' => $col('o', 'order_date'), 'op' => '>=', 'value_type' => 'param', 'value' => 'from_date'],
            ]],
        ]);

        $grid = Meta::where('code', 'proc_supplier_spending_grid')->first();
        if ($grid) {
            MetaParam::where('main_meta_id', $grid->id)->delete();
            $grid->delete();
        }
        $grid = QueryDesign::makeGrid($query, null, 'proc_supplier_spending_grid');
        MetaParam::where('main_meta_id', $grid->id)->where('field_path', 'id')->update(['is_show' => false]);
        MetaParam::where('main_meta_id', $grid->id)->where('field_path', 'from_date')->update(['label_name' => 'Orders from']);
        // The footer counts the orders and adds up the totals.
        MetaParam::where('main_meta_id', $grid->id)->where('field_path', 'orders')->first()?->update(['json_config' => ['aggregate' => 'sum']]);
        MetaParam::where('main_meta_id', $grid->id)->where('field_path', 'total')->first()?->update(['json_config' => ['aggregate' => 'sum']]);

        MetaParam::updateOrCreate(
            ['main_meta_id' => $app->id, 'field_path' => 'supplier_spending', 'parent_id' => null],
            ['param_name' => 'supplier_spending', 'label_name' => 'Supplier spending', 'data_type' => 'string', 'icon_cls' => 'chart', 'ref_meta_id' => $grid->id, 'order_number' => 96, 'is_show' => true],
        );
    }
}
