<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateDocumentMetadataRequest extends FormRequest
{
    public const FIELDS = ['title', 'description', 'document_type_id', 'production_date', 'author', 'interested_party', 'reference_number'];

    public function authorize(): bool
    {
        $document = $this->route('document');

        return $document instanceof Document && Gate::forUser($this->user())->allows('update', $document);
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'document_type_id' => ['sometimes', 'required', 'integer', 'exists:document_types,id'],
            'production_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'author' => ['sometimes', 'nullable', 'string', 'max:255'],
            'interested_party' => ['sometimes', 'nullable', 'string', 'max:255'],
            'reference_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'producing_unit_id' => ['missing'],
            'archived_at' => ['missing'],
            'archived_by' => ['missing'],
            'created_by' => ['missing'],
            'updated_by' => ['missing'],
            'file' => ['missing'],
        ];
    }
}
