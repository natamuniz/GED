<?php

namespace App\Actions;

use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class AddDocumentVersion
{
    /** Called inside the document creation transaction when $initial is true. */
    public function execute(Document $document, UploadedFile $file, User $user, ?string $note = null, bool $initial = false): DocumentVersion
    {
        $path = null;
        try {
            return DB::transaction(function () use ($document, $file, $user, $note, $initial, &$path) {
                if (! $initial) {
                    DB::select('SELECT pg_advisory_xact_lock_shared(71932, 1)');
                    $user = $user->fresh();
                    abort_unless($user?->is_active, 403);
                }
                $locked = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
                if (! $initial) {
                    Gate::forUser($user)->authorize('addVersion', $locked);
                }

                $hash = hash_file('sha256', $file->getRealPath());
                if ($hash === false) {
                    throw new RuntimeException('Não foi possível calcular o hash do arquivo.');
                }
                $path = $file->store('documents/'.$document->id, 'local');
                if ($path === false) {
                    throw new RuntimeException('Não foi possível armazenar o arquivo.');
                }

                $version = $locked->versions()->create([
                    'version_number' => ((int) $locked->versions()->max('version_number')) + 1,
                    'storage_disk' => 'local', 'storage_path' => $path,
                    'original_filename' => $file->getClientOriginalName(),
                    'extension' => strtolower($file->getClientOriginalExtension()),
                    'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                    'size_bytes' => $file->getSize(), 'sha256' => $hash,
                    'change_note' => $note, 'uploaded_by' => $user->id,
                ]);
                AuditEvent::create([
                    'actor_id' => $user->id, 'action' => 'document.version-added',
                    'subject_type' => 'document_version', 'subject_id' => $version->id, 'document_id' => $document->id,
                ]);

                return $version;
            });
        } catch (Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }
}
