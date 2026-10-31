<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class AllowedDocumentFile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('Arquivo inválido.');

            return;
        }
        // Containers such as Office and MSG can have generic detected MIME types.
        // MIME validation is separate from extension validation and never trusts client headers.
        $types = [
            'pdf' => ['application/pdf'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
            'csv' => ['text/plain', 'text/csv', 'application/csv'],
            'mp3' => ['audio/mpeg', 'audio/mp3', 'application/octet-stream'],
            'mp4' => ['video/mp4', 'application/mp4'],
            'eml' => ['message/rfc822', 'text/plain'],
            'msg' => ['application/vnd.ms-outlook', 'application/CDFV2', 'application/x-ole-storage', 'application/octet-stream'],
        ];
        $extension = strtolower($value->getClientOriginalExtension());
        if (! in_array($extension, config('ged.allowed_extensions'), true)
            || ! in_array($value->getMimeType(), $types[$extension] ?? [], true)) {
            $fail('Formato ou conteúdo do arquivo não permitido.');
        }
    }
}
