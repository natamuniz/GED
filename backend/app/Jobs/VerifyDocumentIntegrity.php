<?php

namespace App\Jobs;

use App\Models\AuditEvent;
use App\Models\DocumentVersion;
use App\Models\IntegrityCheck;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class VerifyDocumentIntegrity implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $versionId, public int $requestedBy) {}

    public function handle(): void
    {
        $version = DocumentVersion::findOrFail($this->versionId);
        $user = User::findOrFail($this->requestedBy);
        if (! Gate::forUser($user)->allows('verifyIntegrity', $version->document)) {
            return;
        }
        $stream = null;
        $hash = null;
        $error = null;
        try {
            $stream = Storage::disk($version->storage_disk)->readStream($version->storage_path);
            if (! is_resource($stream)) {
                throw new RuntimeException('Unreadable file');
            }
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);
            if (! feof($stream)) {
                throw new RuntimeException('Incomplete file read');
            }
            $hash = hash_final($context);
            $result = hash_equals($version->sha256, $hash) ? 'match' : 'mismatch';
        } catch (Throwable $exception) {
            report($exception);
            $result = 'error';
            $error = 'file_read_failed';
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        DB::transaction(function () use ($version, $hash, $result, $error) {
            $check = IntegrityCheck::create([
                'document_version_id' => $version->id, 'expected_sha256' => $version->sha256,
                'calculated_sha256' => $hash, 'result' => $result, 'error_code' => $error,
                'requested_by' => $this->requestedBy, 'checked_at' => now(),
            ]);
            AuditEvent::create([
                'actor_id' => $this->requestedBy, 'action' => 'document.integrity-checked',
                'subject_type' => 'integrity_check', 'subject_id' => $check->id,
                'document_id' => $version->document_id, 'metadata' => ['result' => $result],
            ]);
        });
    }
}
