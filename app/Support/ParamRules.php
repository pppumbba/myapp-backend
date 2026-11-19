<?php

namespace App\Support;

use App\Http\Controllers\Api\V1\MetaDataController;
use App\Models\Meta;
use App\Models\MetaParam;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Validation rules from a meta's params: required, data type, min/max, unique and lookups.
 * Forms (MetaDataController) and Excel imports check records with the same rules.
 */
class ParamRules
{
    /** Columns the database fills in itself; forms and imports never write them. */
    public const MANAGED_COLUMNS = ['id', 'created_at', 'updated_at'];

    /**
     * Lines use a prefix like "lines.*."; unique isn't checked there, since each line would
     * need its own exception. $id is the record being changed: it keeps its own unique
     * values, and every rule becomes "sometimes".
     *
     * @param  Collection<int, MetaParam>  $params
     * @param  list<string>  $skip
     * @return array<string, list<mixed>>
     */
    public static function for(Collection $params, string $table, string $key, ?int $id = null, string $prefix = '', array $skip = []): array
    {
        $rules = [];

        foreach ($params as $param) {
            $column = $param->field_path;
            if ($param->is_primary || in_array($column, self::MANAGED_COLUMNS, true) || in_array($column, $skip, true) || Autonumber::isLocked($param) || Formula::of($param) !== null) {
                continue;
            }

            // An editable autonumber may be left blank: it is numbered when the record is saved.
            $required = $param->is_required && ! ($id === null && Autonumber::config($param));
            $rule = [$id ? 'sometimes' : null, $required ? 'required' : 'nullable'];

            switch ($param->data_type) {
                case 'integer':
                    $rule[] = 'integer';
                    break;
                case 'decimal':
                    $rule[] = 'numeric';
                    break;
                case 'boolean':
                    $rule[] = 'boolean';
                    break;
                case 'date':
                case 'datetime':
                    $rule[] = 'date';
                    break;
                case 'text':
                    $rule[] = 'string';
                    break;
                default:
                    $rule[] = 'string';
                    $rule[] = 'max:255';
            }

            if (in_array($param->data_type, ['integer', 'decimal'], true)) {
                if (is_numeric($param->min_value)) {
                    $rule[] = 'min:'.$param->min_value;
                }
                if (is_numeric($param->max_value)) {
                    $rule[] = 'max:'.$param->max_value;
                }
            }

            if ($param->is_unique && $prefix === '') {
                $rule[] = Rule::unique($table, $column)->ignore($id, $key);
            }

            // A lookup must point at a record that exists in the lookup meta's table.
            if ($param->is_select && $param->ref_meta_id) {
                $refTable = self::lookupTable($param);
                if ($refTable) {
                    $rule[] = Rule::exists($refTable, $param->valueField());
                }
            }

            $rules[$prefix.$column] = array_values(array_filter($rule));
        }

        return $rules;
    }

    /** The table a lookup param's meta reads, when it may be read; null otherwise. */
    public static function lookupTable(MetaParam $param): ?string
    {
        $refTable = Meta::whereKey($param->ref_meta_id)->value('table_name');

        return $refTable && ! in_array(strtolower($refTable), MetaDataController::BLOCKED_TABLES, true) && Schema::hasTable($refTable) ? $refTable : null;
    }
}
