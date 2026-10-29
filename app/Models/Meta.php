<?php

namespace App\Models;

use App\Casts\MetaConfig;
use App\Models\Concerns\HasMetaLinks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A screen or setting of the builder. Links to other metas inside json_config live in
 * meta_meta_map, and what a notification watches in meta_notification_map (see MetaConfig).
 */
class Meta extends Model
{
    use HasMetaLinks;

    protected $table = 'metas';

    protected $fillable = [
        'code', 'name', 'label_name', 'is_active', 'is_log', 'is_public', 'created_by', 'updated_by',
        'ref_meta_id', 'description', 'table_name', 'query_string',
        'json_config', 'type_id', 'widget_id',
        // A form's scripts: on load, on change, before and after save, and shared variables and functions.
        'load_expression_string', 'event_expression_string', 'before_save_expression_string',
        'after_save_expression_string', 'var_fnc_expression_string',
        // An external REST service read and written instead of a table (see ExternalService).
        'service_url', 'service_headers', 'service_query', 'service_list_path',
        'service_total_path', 'service_record_path', 'service_update_method', 'service_list_body',
    ];

    /**
     * Links show inside json_config, as the screens read them. A service's headers and list body
     * may hold credentials, so only admins see them (MetaController makes them visible).
     */
    protected $hidden = ['links', 'notificationLink', 'service_headers', 'service_list_body'];

    protected $casts = [
        'is_active' => 'boolean',
        'is_log' => 'boolean',
        'is_public' => 'boolean',
        'json_config' => MetaConfig::class,
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(MetaType::class, 'type_id');
    }

    /** For a widget meta: the library entry it is; its json_config holds the prop values. */
    public function widget(): BelongsTo
    {
        return $this->belongsTo(Widget::class, 'widget_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function refMeta(): BelongsTo
    {
        return $this->belongsTo(Meta::class, 'ref_meta_id');
    }

    /** Links held in this meta's own json_config: drilldown, matrix rows and columns, link. */
    public function links(): HasMany
    {
        return $this->hasMany(MetaMetaMap::class, 'src_meta_id')->whereNull('src_param_id')->orderBy('id');
    }

    /** For a notification: the meta (or workflow) and status it watches. */
    public function notificationLink(): HasOne
    {
        return $this->hasOne(MetaNotificationMap::class, 'notification_id');
    }

    /** What this meta is for (meta_tag_map). */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'meta_tag_map')->withTimestamps()->orderBy('name');
    }

    public function params(): HasMany
    {
        return $this->hasMany(MetaParam::class, 'main_meta_id')->orderBy('order_number');
    }
}
