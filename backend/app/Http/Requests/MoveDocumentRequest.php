<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class MoveDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $document = $this->route('document');

        return $document instanceof Document
            && Gate::forUser($this->user())->allows('move', [$document, $document->producing_unit_id]);
    }

    public function rules(): array
    {
        return [
            'producing_unit_id' => ['present', 'nullable', 'integer', Rule::exists('organizational_units', 'id')->where('is_active', true)],
        ];
    }
}
