<?php

namespace App\Services;

use App\Http\Requests\StoreDocumentShareRequest;
use App\Models\AuditEvent;
use App\Models\DocumentShare;
use App\Models\DocumentVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DocumentSharing
{
    public const RELATIONS = [
        'version.document.type', 'version.document.producingUnit',
        'creator.profile.permissions', 'creator.permissionOverrides', 'recipients:id,name,email',
    ];

    /** @return array{share: DocumentShare, token: string} */
    public function create(StoreDocumentShareRequest $request, DocumentVersion $version): array
    {
        return DB::transaction(function () use ($request, $version) {
            DB::select('SELECT pg_advisory_xact_lock(71932, 1)');
            $actor = $request->user()->fresh();
            $version = $version->fresh('document');
            Gate::forUser($actor)->authorize('share', $version->document);
            $data = Validator::make($request->all(), $request->rules())->validate();
            $startsAt = (isset($data['starts_at']) ? CarbonImmutable::parse($data['starts_at'])->utc() : CarbonImmutable::now('UTC'))->startOfSecond();
            $expiresAt = CarbonImmutable::parse($data['expires_at'])->utc()->startOfSecond();
            if ($expiresAt->lessThanOrEqualTo($startsAt) || $expiresAt->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
                throw ValidationException::withMessages(['expires_at' => 'A expiração deve ser futura e posterior ao início da validade.']);
            }
            $token = bin2hex(random_bytes(32));
            $share = DocumentShare::create([
                'document_version_id' => $version->id, 'token_hash' => hash('sha256', $token),
                'created_by' => $actor->id, 'starts_at' => $startsAt, 'expires_at' => $expiresAt,
            ]);
            $share->recipients()->sync($data['recipient_ids']);
            $this->audit($actor, $share, 'share.created', [
                'recipient_ids' => $data['recipient_ids'], 'starts_at' => $startsAt->toIso8601String(),
                'expires_at' => $expiresAt->toIso8601String(),
            ]);

            return ['share' => $share->load(self::RELATIONS), 'token' => $token];
        });
    }

    public function resolveToken(string $token): DocumentShare
    {
        return DocumentShare::where('token_hash', hash('sha256', $token))->firstOrFail();
    }

    public function read(User $actor, Closure $operation): mixed
    {
        return DB::transaction(function () use ($actor, $operation) {
            // Administrative mutations and share revocation use the exclusive counterpart.
            DB::select('SELECT pg_advisory_xact_lock_shared(71932, 1)');
            $actor = $actor->fresh();
            abort_unless($actor?->is_active, 403);

            return $operation($actor);
        });
    }

    public function access(User $actor, DocumentShare $share, string $action, Closure $operation): mixed
    {
        return $this->read($actor, function (User $actor) use ($share, $action, $operation) {
            $share = DocumentShare::with(self::RELATIONS)->findOrFail($share->id);
            abort_unless($share->recipients->contains('id', $actor->id), 403, 'Você não é destinatário deste compartilhamento.');
            $state = $this->state($share);
            abort_if(in_array($state, ['expired', 'revoked'], true), 410, 'Compartilhamento expirado ou revogado.');
            abort_unless($state === 'active', 403, 'Compartilhamento indisponível.');
            $result = $operation($share);
            $this->audit($actor, $share, $action);

            return $result;
        });
    }

    public function revoke(User $actor, DocumentShare $share): DocumentShare
    {
        return DB::transaction(function () use ($actor, $share) {
            DB::select('SELECT pg_advisory_xact_lock(71932, 1)');
            $actor = $actor->fresh();
            $share = DocumentShare::with('version.document')->findOrFail($share->id);
            $owns = $actor?->hasPermission('shares.revoke-own') && $share->created_by === $actor->id;
            $manages = $actor?->hasPermission('shares.revoke-any') && Gate::forUser($actor)->allows('view', $share->version->document);
            abort_unless($owns || $manages, 403);
            if ($share->revoked_at === null) {
                $share->revoked_at = now();
                $share->revoked_by = $actor->id;
                $share->save();
                $this->audit($actor, $share, 'share.revoked');
            }

            return $share;
        });
    }

    public function state(DocumentShare $share): string
    {
        if ($share->revoked_at !== null) {
            return 'revoked';
        }
        if (now()->greaterThanOrEqualTo($share->expires_at)) {
            return 'expired';
        }
        if (now()->lessThan($share->starts_at)) {
            return 'scheduled';
        }
        $creator = $share->creator;
        if (! $creator?->hasPermission('shares.create') || ! Gate::forUser($creator)->allows('download', $share->version->document)) {
            return 'unavailable';
        }

        return 'active';
    }

    private function audit(User $actor, DocumentShare $share, string $action, ?array $metadata = null): void
    {
        AuditEvent::create([
            'actor_id' => $actor->id, 'action' => $action,
            'subject_type' => 'document_share', 'subject_id' => $share->id,
            'document_id' => $share->version->document_id, 'document_share_id' => $share->id,
            'metadata' => $metadata,
        ]);
    }
}
