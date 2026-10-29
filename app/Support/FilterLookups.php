<?php

namespace App\Support;

use App\Models\Meta;
use App\Models\MetaAction;
use App\Models\MetaParam;
use Illuminate\Support\Collection;

/**
 * Filters pick values the way the form does. A filter (is_criteria) of a grid, statement,
 * calendar or gantt that is neither a lookup nor a list of options itself borrows them from
 * the same-named field of the form its records are entered with: the form of its Add or
 * Edit button, else the form it refers to (ref_meta_id). So "Supplier" filters with the
 * form's supplier lookup and "Status" with its list, without setting them twice.
 */
class FilterLookups
{
    /** Meta types whose params can be filters. */
    public const TYPES = ['datagrid', 'statement', 'calendar', 'gantt'];

    /**
     * For each filter that borrows, the form field it borrows from, by field_path.
     *
     * @param  Collection<int, MetaParam>  $params
     * @return array<string, MetaParam>
     */
    public static function borrowed(Meta $meta, Collection $params): array
    {
        $meta->loadMissing('type');
        if (! in_array($meta->type?->code, self::TYPES, true)) {
            return [];
        }
        $plain = $params->filter(fn (MetaParam $p) => $p->is_criteria && ! self::picks($p));
        if ($plain->isEmpty() || ! ($formId = self::formOf($meta))) {
            return [];
        }

        $fields = MetaParam::where('main_meta_id', $formId)->whereIn('field_path', $plain->pluck('field_path'))->get()->keyBy('field_path');

        return $plain->mapWithKeys(fn (MetaParam $p) => [$p->field_path => $fields->get($p->field_path)])
            ->filter(fn (?MetaParam $f) => $f && self::picks($f))
            ->all();
    }

    /** What a borrowing filter picks with, as the screen reads it (MetaParam's own names). */
    public static function describe(MetaParam $field): array
    {
        return $field->is_select && $field->ref_meta_id
            ? ['is_select' => true, 'ref_meta_id' => $field->ref_meta_id, 'display_field' => $field->display_field, 'display_as' => $field->display_as, 'value_field' => $field->value_field]
            : ['options' => $field->json_config['options']];
    }

    /** Whether a param picks from records (a lookup) or a list (options). */
    public static function picks(MetaParam $param): bool
    {
        return ($param->is_select && $param->ref_meta_id) || is_array($param->json_config['options'] ?? null);
    }

    /** The form records of this meta are entered with. */
    private static function formOf(Meta $meta): ?int
    {
        $isForm = fn (?int $id) => $id && Meta::whereKey($id)->whereHas('type', fn ($q) => $q->where('code', 'form'))->exists();
        // A grid that reuses another enters its records with the buttons it uses (GridReuse).
        $owner = $meta->type?->code === 'datagrid' ? GridReuse::actionsOf($meta) : $meta;
        $action = MetaAction::where('grid_meta_id', $owner->id)->whereIn('action', ['add', 'edit'])->whereNotNull('form_meta_id')
            ->orderByRaw("CASE WHEN action = 'add' THEN 0 ELSE 1 END")->value('form_meta_id');

        return $isForm($action) ? $action : ($isForm($owner->ref_meta_id) ? $owner->ref_meta_id : null);
    }
}
