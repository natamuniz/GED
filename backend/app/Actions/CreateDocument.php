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
use Throwable;

class CreateDocument
{
    public function __construct(private AddDocumentVersion $versions) {}

    public function execute(array $attributes, UploadedFile $file, User $user): Document
    {
        Gate::forUser($user)->authorize('create', [Document::class, $attributes['producing_unit_id'] ?? null]);
        $version = null;
        try {
            return DB::transaction(function () use ($attributes, $file, $user, &$version) {
                $document = new Document($attributes);
                $document->created_by = $user->id;
                $document->updated_by = $user->id;
                $document->save();
                $version = $this->versions->execute($document, $file, $user, initial: true);
                AuditEvent::create([
                    'actor_id' => $user->id, 'action' => 'document.created',
                    'subject_type' => 'document', 'subject_id' => $document->id, 'document_id' => $document->id,
                ]);

                return $document->load('type', 'producingUnit', 'latestVersion');
            });
        } catch (Throwable $exception) {
            if ($version instanceof DocumentVersion) {
                Storage::disk($version->storage_disk)->delete($version->storage_path);
            }
            throw $exception;
        }
    }
}
