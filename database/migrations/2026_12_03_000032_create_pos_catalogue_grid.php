<?php

use App\Models\Meta;
use App\Models\MetaAction;
use App\Models\MetaParam;
use App\Models\MetaPermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An example of a grid showing its records as widgets: the Catalogue, under Products in the
 * point of sale app. What's on sale as cards, each column at its widget_position. The pictures
 * are placeholders from placehold.co that spell out the product's name.
 *
 * Idempotent: the meta is matched by code, its params and actions rebuilt. MySQL/MariaDB only.
 */
return new class extends Migration
{
    private const CODE = 'pos_catalogue_grid';

    private const QUERY = <<<'SQL'
        SELECT i.id, i.code, i.name, c.name AS category, p.sale_price, t.name AS tax, COALESCE(s.on_hand, 0) AS on_hand, i.unit,
         CONCAT('https://placehold.co/480x270/eef2ff/3730a3?text=', REPLACE(i.name, ' ', '+')) AS picture
        FROM pos_products p
        JOIN proc_items i ON i.id = p.item_id
        LEFT JOIN proc_item_categories c ON c.id = i.category_id
        LEFT JOIN pos_tax_rates t ON t.id = p.tax_rate_id
        LEFT JOIN (SELECT item_id, SUM(quantity) AS on_hand FROM wh_stocks GROUP BY item_id) s ON s.item_id = i.id
        WHERE p.on_sale = 1 AND COALESCE(i.is_active, 1) = 1
        SQL;

    /** @var list<array{0: string, 1: string, 2: string, 3: ?string}> field, label, type, place on the widget */
    private const COLUMNS = [
        ['picture', 'Picture', 'string', 'image'],
        ['name', 'Name', 'string', 'title'],
        ['code', 'Code', 'string', 'subtitle'],
        ['category', 'Category', 'string', 'badge'],
        ['on_hand', 'On hand', 'decimal', 'body'],
        ['tax', 'Tax', 'string', 'body'],
        ['sale_price', 'Price', 'decimal', 'footer'],
        ['unit', 'Unit', 'string', 'footer'],
    ];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        $products = Meta::where('code', 'pos_products_grid')->first();
        $app = Meta::where('code', 'pos_app')->first();
        if (! $products || ! $app) {
            return;
        }

        DB::transaction(function () use ($products, $app) {
            $grid = Meta::updateOrCreate(['code' => self::CODE], [
                'name' => 'Catalogue',
                'type_id' => $products->type_id,
                'table_name' => $products->table_name,
                'description' => 'What is on sale, as cards.',
                'json_config' => ['view' => 'cards'],
                'query_string' => self::QUERY,
                'is_active' => true,
            ]);

            $this->columns($grid);
            $this->actions($products, $grid);
            $this->menu($app, $grid);

            MetaPermission::where('meta_id', $grid->id)->delete();
            foreach (MetaPermission::where('meta_id', $products->id)->get() as $permission) {
                $copy = $permission->replicate();
                $copy->meta_id = $grid->id;
                $copy->save();
            }
        });
    }

    public function down(): void
    {
        $grid = Meta::where('code', self::CODE)->first();
        if ($grid) {
            MetaParam::where('ref_meta_id', $grid->id)->delete();
            $grid->delete();
        }
    }

    private function columns(Meta $grid): void
    {
        MetaParam::where('main_meta_id', $grid->id)->delete();
        MetaParam::create([
            'main_meta_id' => $grid->id, 'order_number' => 1, 'field_path' => 'id', 'param_name' => 'id',
            'label_name' => 'ID', 'data_type' => 'integer', 'is_primary' => true, 'is_show' => false,
        ]);
        foreach (self::COLUMNS as $i => [$field, $label, $type, $place]) {
            MetaParam::create([
                'main_meta_id' => $grid->id, 'order_number' => $i + 2, 'field_path' => $field, 'param_name' => $field,
                'label_name' => $label, 'data_type' => $type, 'widget_position' => $place, 'is_show' => true,
                'is_label' => $field === 'name', 'is_criteria' => in_array($field, ['name', 'code', 'category'], true),
            ]);
        }
    }

    /** The Products grid's buttons, so a card opens the same form. */
    private function actions(Meta $products, Meta $grid): void
    {
        MetaAction::where('grid_meta_id', $grid->id)->delete();
        foreach (MetaAction::where('grid_meta_id', $products->id)->get() as $action) {
            $copy = $action->replicate();
            $copy->grid_meta_id = $grid->id;
            $copy->save();
        }
    }

    /** Under Products in the app's menu. */
    private function menu(Meta $app, Meta $grid): void
    {
        $group = MetaParam::where('main_meta_id', $app->id)->where('field_path', 'products')->whereNull('parent_id')->first();
        if (! $group) {
            return;
        }
        MetaParam::updateOrCreate(['main_meta_id' => $app->id, 'parent_id' => $group->id, 'field_path' => 'catalogue'], [
            'order_number' => 6, 'param_name' => 'catalogue', 'label_name' => 'Catalogue',
            'data_type' => 'string', 'ref_meta_id' => $grid->id, 'is_show' => true,
        ]);
    }
};
