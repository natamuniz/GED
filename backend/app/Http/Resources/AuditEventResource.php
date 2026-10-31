<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'actor_id' => $this->actor_id,
            'actor' => $this->actor?->only('id', 'name'),
            'document_id' => $this->document_id,
            'document_share_id' => $this->document_share_id,
            'document' => $this->document === null ? null : [
                ...$this->document->only('id', 'title', 'producing_unit_id', 'archived_at'),
                'producing_unit' => $this->document->producingUnit?->only('id', 'name', 'code'),
            ],
            'changes' => $this->sanitize($this->changes),
            'metadata' => $this->sanitize($this->metadata),
            'created_at' => $this->created_at,
        ];
    }

    private function sanitize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $safe = [];
        foreach ($value as $key => $entry) {
            $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $key));
            if (in_array($normalized, [
                'password', 'passwordconfirmation', 'passwordhash', 'remembertoken',
                'token', 'tokenhash', 'accesstoken', 'refreshtoken', 'csrftoken', 'xsrftoken',
                'secret', 'clientsecret', 'appkey', 'authorization', 'cookie', 'setcookie',
                'storagedisk', 'storagepath', 'accessurl', 'downloadurl',
            ], true)) {
                continue;
            }
            $safe[$key] = $this->sanitize($entry);
        }

        return $safe;
    }
}
