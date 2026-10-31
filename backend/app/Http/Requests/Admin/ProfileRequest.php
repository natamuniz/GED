<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('profiles.manage');
    }

    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'required', 'string', 'max:255', Rule::unique('profiles')->ignore($this->route('profile')?->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'permission_ids' => [$this->isMethod('POST') ? 'present' : 'sometimes', 'array', 'max:500'],
            'permission_ids.*' => ['required', 'integer', 'distinct', 'exists:permissions,id'],
            'is_system' => ['prohibited'],
        ];
    }
}
