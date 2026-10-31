<?php

namespace App\Http\Requests;

use App\Models\DocumentVersion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreDocumentShareRequest extends FormRequest
{
    public function authorize(): bool
    {
        $version = $this->route('version');

        return $version instanceof DocumentVersion && Gate::forUser($this->user())->allows('share', $version->document);
    }

    public function rules(): array
    {
        $timestamp = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/';

        return [
            'recipient_ids' => ['required', 'array', 'min:1', 'max:100'],
            'recipient_ids.*' => ['required', 'integer', 'distinct', Rule::exists('users', 'id')->where('is_active', true)],
            'starts_at' => ['sometimes', 'nullable', 'date', 'regex:'.$timestamp],
            'expires_at' => ['required', 'date', 'regex:'.$timestamp, 'after:now'],
        ];
    }
}
