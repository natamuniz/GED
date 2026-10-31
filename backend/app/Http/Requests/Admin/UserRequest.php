<?php

namespace App\Http\Requests\Admin;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('users.manage')
            && ((! $this->has('profile_id') && ! $this->has('permission_overrides'))
                || $this->user()->hasPermission('profiles.manage'));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';
        $existingUnitIds = $this->route('user')?->units()->pluck('organizational_units.id')->all() ?? [];

        return [
            'name' => [$presence, 'required', 'string', 'max:255'],
            'email' => [$presence, 'required', 'email', 'max:255', Rule::unique('users')->ignore($this->route('user')?->id)],
            'password' => [$presence, 'required', 'string', 'min:12', 'max:255', 'confirmed'],
            'is_active' => ['sometimes', 'boolean'],
            'profile_id' => ['sometimes', 'nullable', 'integer', 'exists:profiles,id'],
            'unit_ids' => ['sometimes', 'array', 'max:500'],
            'unit_ids.*' => ['required', 'integer', 'distinct', Rule::exists('organizational_units', 'id')->where(
                fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('is_active', true)->orWhereIn('id', $existingUnitIds))
            )],
            'permission_overrides' => ['sometimes', 'array', 'max:500'],
            'permission_overrides.*' => ['required', 'array:permission_id,effect'],
            'permission_overrides.*.permission_id' => ['required', 'integer', 'distinct', 'exists:permissions,id'],
            'permission_overrides.*.effect' => ['required', Rule::in(['allow', 'deny'])],
        ];
    }
}
