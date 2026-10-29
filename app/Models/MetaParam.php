<?php

namespace App\Models;

use App\Casts\MetaConfig;
use App\Models\Concerns\HasMetaLinks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row of a meta's structure. Its meaning depends on the owner's type:
 *  - owner app   -> menu item (ref_meta_id = the screen it opens)
 *  - owner datagrid -> column (ref_meta_id = lookup meta, if any)
 *  - owner form  -> field     (ref_meta_id = lookup/related meta)
 *  - owner tabs  -> tab       (ref_meta_id = the meta the tab shows)
 * parent_id nests rows inside each other at any level.
 *
 * A lookup (is_select with ref_meta_id) stores its record's value_field (id by default) and
 * shows its display_field (by default the looked-up meta's is_label field, else name).
 * is_label marks the field that names a meta's records; is_parent the field holding the id of
 * a record's parent in the same table, so a grid shows them as a tree. Links to other metas inside
 * json_config live in meta_meta_map (see MetaConfig).
 */
class MetaParam extends Model
{
    use HasMetaLinks;

    protected $table = 'meta_params';

    protected $fillable = [
        'order_number',
        'parent_id',
        'field_path',
        'param_name',
        'column_path',
        'label_name',
        'data_type',
        'data_mask',
        'render_type',
        'icon_cls',
        'main_meta_id',
        'ref_meta_id',
        'is_primary',
        'is_show',
        'is_required',
        'is_criteria',
        'is_unique',
        'is_merge',
        'is_select',
        'is_mandatory_criteria',
        'default_value',
        'min_value',
        'max_value',
        'column_width',
        'expression_string',
        'json_config',
        'display_field',
        'value_field',
        'is_label',
        'is_parent',
    ];

    /** Links show inside json_config, as the screens read them. */
    protected $hidden = ['links'];

    /** Loaded with the params, in one query: json_config reads them for every param. */
    protected $with = ['links'];

    /** For a lookup, the field it shows, so screens needn't load the looked-up meta to know. */
    protected $appends = ['display_as'];

    protected $casts = [
        'order_number' => 'integer',
        'is_primary' => 'boolean',
        'is_show' => 'boolean',
        'is_required' => 'boolean',
        'is_criteria' => 'boolean',
        'is_unique' => 'boolean',
        'is_merge' => 'boolean',
        'is_select' => 'boolean',
        'is_mandatory_criteria' => 'boolean',
        'is_label' => 'boolean',
        'is_parent' => 'boolean',
        'json_config' => MetaConfig::class,
    ];

    /** The meta (app, datagrid, form, ...) this row belongs to. */
    public function main(): BelongsTo
    {
        return $this->belongsTo(Meta::class, 'main_meta_id');
    }

    /** The meta this row opens or links to. */
    public function refMeta(): BelongsTo
    {
        return $this->belongsTo(Meta::class, 'ref_meta_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(MetaParam::class, 'parent_id');
    }

    /** Links held in this row's json_config: drilldown, subgrid, scope, detail. */
    public function links(): HasMany
    {
        return $this->hasMany(MetaMetaMap::class, 'src_param_id')->orderBy('id');
    }

    /** The looked-up record's column shown for it: display_field, else its meta's is_label field, else name. */
    public function displayField(): string
    {
        return $this->display_field
            ?: ($this->ref_meta_id ? MetaParam::where('main_meta_id', $this->ref_meta_id)->where('is_label', true)->value('field_path') : null)
            ?: 'name';
    }

    public function getDisplayAsAttribute(): ?string
    {
        return $this->is_select && $this->ref_meta_id ? $this->displayField() : null;
    }

    /** The looked-up record's column a lookup stores: value_field, else id. */
    /** What people call this field: its label, else its name. */
    public function label(): string
    {
        return $this->label_name ?: ($this->param_name ?: $this->field_path);
    }

    public function valueField(): string
    {
        return $this->value_field ?: 'id';
    }

    public function children(): HasMany
    {
        return $this->hasMany(MetaParam::class, 'parent_id')->orderBy('order_number');
    }
}
