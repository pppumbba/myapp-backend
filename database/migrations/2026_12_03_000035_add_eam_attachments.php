<?php

use App\Models\Meta;
use App\Models\MetaParam;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Photos and documents on the Assets app's work orders and assets: a multifile field on each
 * form (files live in contents/content_map, so no table changes), and a Files column on the work
 * order grid. EamDemoSeeder has the same for fresh databases.
 */
return new class extends Migration
{
    /** Pictures a phone takes, PDFs and office files, up to 10 MB each, ten per record. */
    public const CONFIG = [
        'accept' => ['jpg', 'jpeg', 'png', 'heic', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx'],
        'max_size' => 10240,
        'max_files' => 10,
    ];

    public function up(): void
    {
        DB::transaction(function () {
            // On the work order, after "What was done" and before the parts.
            $this->add('eam_work_order_form', 'attachments', 'Photos and documents', 'multifile', 'resolution', ['json_config' => self::CONFIG]);
            $this->add('eam_asset_form', 'attachments', 'Photos and documents', 'multifile', 'notes', ['json_config' => self::CONFIG]);
            $this->add('eam_work_orders_grid', 'attachments', 'Files', 'multifile', 'total_cost');
        });
    }

    public function down(): void
    {
        $ids = Meta::whereIn('code', ['eam_work_order_form', 'eam_asset_form', 'eam_work_orders_grid'])->pluck('id');
        MetaParam::whereIn('main_meta_id', $ids)->where('field_path', 'attachments')->delete();
    }

    /** Puts the field right after [after] on that meta (the ones below move down), once. */
    private function add(string $code, string $field, string $label, string $type, string $after, array $extra = []): void
    {
        $meta = Meta::where('code', $code)->first();
        if (! $meta || MetaParam::where('main_meta_id', $meta->id)->where('field_path', $field)->exists()) {
            return;
        }
        $order = (int) (MetaParam::where('main_meta_id', $meta->id)->whereNull('parent_id')->where('field_path', $after)->value('order_number')
            ?? MetaParam::where('main_meta_id', $meta->id)->whereNull('parent_id')->max('order_number'));
        MetaParam::where('main_meta_id', $meta->id)->whereNull('parent_id')->where('order_number', '>', $order)->increment('order_number');
        MetaParam::create($extra + [
            'main_meta_id' => $meta->id,
            'order_number' => $order + 1,
            'field_path' => $field,
            'param_name' => $field,
            'label_name' => $label,
            'data_type' => $type,
            'is_show' => true,
        ]);
    }
};
