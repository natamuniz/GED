<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('units.manage');
    }

    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'required', 'string', 'max:255'],
            'code' => [$presence, 'required', 'string', 'max:255', Rule::unique('organizational_units')->ignore($this->route('unit')?->id)],
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('organizational_units', 'id')->where('is_active', true)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
