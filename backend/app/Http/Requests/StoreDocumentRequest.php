<?php

namespace App\Http\Requests;

use App\Rules\AllowedDocumentFile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Action authorizes the validated target unit.
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'document_type_id' => ['required', 'integer', Rule::exists('document_types', 'id')->where('is_active', true)],
            'producing_unit_id' => ['nullable', 'integer', Rule::exists('organizational_units', 'id')->where('is_active', true)],
            'production_date' => ['required', 'date_format:Y-m-d'],
            'author' => ['nullable', 'string', 'max:255'],
            'interested_party' => ['nullable', 'string', 'max:255'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:'.config('ged.max_upload_kb'), new AllowedDocumentFile],
        ];
    }
}
