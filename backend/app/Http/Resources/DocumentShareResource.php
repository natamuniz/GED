<?php

namespace App\Http\Resources;

use App\Services\DocumentSharing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentShareResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $state = app(DocumentSharing::class)->state($this->resource);
        $management = $request->routeIs('shares.store', 'shares.index');
        $available = $state === 'active';
        $version = $this->version;
        $document = $version->document;

        return [
            'id' => $this->id,
            'created_by' => $this->created_by,
            'starts_at' => $this->starts_at,
            'expires_at' => $this->expires_at,
            'revoked_at' => $this->revoked_at,
            'state' => $state,
            'created_at' => $this->created_at,
            'recipients' => $this->when($management, fn () => $this->recipients->map(fn ($user) => $user->only('id', 'name', 'email'))),
            'document' => $this->when($management || $available, fn () => [
                ...$document->only('id', 'title', 'description', 'author', 'interested_party', 'reference_number'),
                'production_date' => $document->production_date->format('Y-m-d'),
                'type' => $document->type->only('id', 'name'),
                'producing_unit' => $document->producingUnit?->only('id', 'name'),
            ]),
            'version' => $this->when($management || $available, fn () => $version->only(
                'id', 'version_number', 'original_filename', 'mime_type', 'size_bytes', 'sha256', 'created_at'
            )),
            'access_url' => $this->when($available && ! $management, fn () => route('shares.access', ['share' => $this->id])),
            'download_url' => $this->when($available && ! $management, fn () => route('shares.download', ['share' => $this->id])),
        ];
    }
}
