<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One kind of widget in the page builder's library. renderer picks the built-in component
 * that draws it; props lists the settings it takes ({key, label, type, default, ...}).
 * Several library entries may share a renderer with different props or defaults, e.g.
 * "Data grid" and "Chart" both embed a meta. Placed widgets are metas (metas.widget_id).
 *
 * A composite widget is made of other widgets: its parts are laid out on its template meta
 * (template_meta_id, type widget_template) like a page, and "{{key}}" in a part's settings
 * takes the value of the composite's own setting with that key. A records widget shows the
 * rows of a grid through lines with "{field}" placeholders.
 */
class Widget extends Model
{
    /** The renderers the frontend draws; section is the only one that holds other widgets. */
    public const RENDERERS = [
        'section', 'divider', 'spacer', 'heading', 'text', 'image', 'callout', 'button',
        'links', 'stat', 'hero', 'list', 'meta', 'chart', 'iframe', 'composite', 'records',
        'photo', 'table', 'schedule', 'carousel', 'gallery', 'accordion', 'collapse', 'tabs', 'quote', 'webform',
    ];

    public const CONTAINERS = ['section'];

    /** What a prop's type may be. meta picks a meta; list is rows of the prop's fields. */
    public const PROP_TYPES = ['text', 'textarea', 'number', 'select', 'boolean', 'url', 'image', 'meta', 'list'];

    protected $fillable = ['code', 'name', 'category', 'renderer', 'template_meta_id', 'icon', 'description', 'props', 'is_active', 'order_number'];

    protected $casts = [
        'props' => 'array',
        'is_active' => 'boolean',
        'order_number' => 'integer',
    ];

    public function metas(): HasMany
    {
        return $this->hasMany(Meta::class, 'widget_id');
    }

    /** A composite's parts, laid out like a page. */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Meta::class, 'template_meta_id');
    }

    public function isContainer(): bool
    {
        return in_array($this->renderer, self::CONTAINERS, true);
    }

    /**
     * The prop values a new widget starts with: each prop's default.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        $values = [];
        foreach ($this->props ?? [] as $prop) {
            if (isset($prop['key']) && array_key_exists('default', $prop)) {
                $values[$prop['key']] = $prop['default'];
            }
        }

        return $values;
    }
}
