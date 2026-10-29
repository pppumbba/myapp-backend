<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MetaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Replace with your policy/permission check
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $req = $isUpdate ? 'sometimes' : 'required';
        $metaId = $this->route('meta')?->id;

        return [
            'code' => [$req, 'string', 'max:100', Rule::unique('metas', 'code')->ignore($metaId)],
            'name' => [$req, 'string', 'max:255'],
            'label_name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'type_id' => ['nullable', 'integer', 'exists:meta_types,id'],
            'ref_meta_id' => ['nullable', 'integer', 'exists:metas,id', Rule::notIn([$metaId])],
            'description' => ['nullable', 'string'],
            'table_name' => ['nullable', 'string', 'max:191'],
            'query_string' => ['nullable', 'string'],
            'json_config' => ['nullable', 'array'],
            'params' => ['sometimes', 'array'],
            'params.*.param_name' => ['required', 'string', 'max:255'],
            'params.*.order_number' => ['required', 'integer', 'min:0'],
            'params.*.field_path' => ['required', 'string', 'max:255'],
            'params.*.data_type' => ['required', 'string', 'max:50'],
            'params.*.column_path' => ['nullable', 'string', 'max:255'],
            'params.*.label_name' => ['nullable', 'string', 'max:255'],
            'params.*.is_primary' => ['sometimes', 'boolean'],
            'params.*.is_show' => ['sometimes', 'boolean'],
            'params.*.is_required' => ['sometimes', 'boolean'],
            'params.*.is_criteria' => ['sometimes', 'boolean'],
            'params.*.ref_meta_id' => ['nullable', 'integer', 'exists:metas,id'],
            'params.*.json_config' => ['nullable', 'array'],
        ];
    }
}
