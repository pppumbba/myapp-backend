<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One button of a datagrid: add, edit, delete, custom, import or stage_query. It shows in
 * the toolbar (show_in_toolbar), on each row (show_in_row) or both, and always in the
 * right-click menu. form_meta_id is the form it opens (for delete, the form whose table loses the row; for import, the
 * excel_import meta that loads the file; for stage_query, the stage query it runs);
 * get_meta_id decides the values that form (or stage query's parameters) opens
 * with. json_config.params maps the get meta's named parameters to fields of the selected
 * row, e.g. {"id": "id"}.
 */
class MetaAction extends Model
{
    public const ACTIONS = ['add', 'edit', 'delete', 'custom', 'import', 'stage_query'];

    protected $fillable = [
        'grid_meta_id', 'action', 'label', 'icon_cls', 'form_meta_id', 'get_meta_id',
        'needs_row', 'show_in_toolbar', 'show_in_row', 'order_number', 'json_config',
    ];

    /** Matches the column default, so a new action says so before it is reloaded. */
    protected $attributes = ['show_in_toolbar' => true];

    protected $casts = [
        'needs_row' => 'boolean',
        'show_in_toolbar' => 'boolean',
        'show_in_row' => 'boolean',
        'json_config' => 'array',
    ];

    public function grid(): BelongsTo
    {
        return $this->belongsTo(Meta::class, 'grid_meta_id');
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Meta::class, 'form_meta_id');
    }

    public function get(): BelongsTo
    {
        return $this->belongsTo(Meta::class, 'get_meta_id');
    }
}
