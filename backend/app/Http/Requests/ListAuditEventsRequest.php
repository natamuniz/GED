<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListAuditEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('audit.view') || $this->user()->hasPermission('audit.view-global');
    }

    public function rules(): array
    {
        return [
            'action' => ['sometimes', 'required', 'string', 'max:255'],
            'actor_id' => ['sometimes', 'integer', 'min:1'],
            'document_id' => ['sometimes', 'integer', 'min:1'],
            'document_share_id' => ['sometimes', 'integer', 'min:1'],
            'producing_unit_id' => ['sometimes', 'integer', 'min:1'],
            'subject_type' => ['sometimes', 'required', 'string', 'max:255'],
            'subject_id' => ['sometimes', 'integer', 'min:1'],
            'date_from' => ['sometimes', 'date', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date', 'date_format:Y-m-d', ...($this->filled('date_from') ? ['after_or_equal:date_from'] : [])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
