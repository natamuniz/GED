<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use App\Services\UnitAccess;

class DocumentPolicy
{
    public function __construct(private UnitAccess $units) {}

    public function view(User $user, Document $document): bool
    {
        return $user->hasPermission('documents.view') && $this->units->contains($user, $document->producing_unit_id);
    }

    public function download(User $user, Document $document): bool
    {
        return $this->view($user, $document) && $user->hasPermission('documents.download');
    }

    public function create(User $user, ?int $unitId = null): bool
    {
        return $user->hasPermission('documents.create')
            && $this->units->contains($user, $unitId)
            && ($unitId !== null || $user->hasPermission('documents.manage-general'));
    }

    public function addVersion(User $user, Document $document): bool
    {
        return $this->view($user, $document)
            && $user->hasPermission('documents.add-version')
            && $document->archived_at === null
            && ($document->producing_unit_id !== null || $user->hasPermission('documents.manage-general'));
    }

    public function share(User $user, Document $document): bool
    {
        return $this->download($user, $document)
            && $user->hasPermission('shares.create') && $document->archived_at === null;
    }

    public function verifyIntegrity(User $user, Document $document): bool
    {
        return $this->view($user, $document) && $user->hasPermission('integrity.verify');
    }

    public function update(User $user, Document $document): bool
    {
        return $this->view($user, $document)
            && $user->hasPermission('documents.update')
            && $document->archived_at === null
            && ($document->producing_unit_id !== null || $user->hasPermission('documents.manage-general'));
    }

    public function move(User $user, Document $document, ?int $destinationUnitId): bool
    {
        return $this->view($user, $document)
            && $user->hasPermission('documents.move')
            && $document->archived_at === null
            && $this->units->contains($user, $destinationUnitId)
            && (($document->producing_unit_id !== null && $destinationUnitId !== null)
                || $user->hasPermission('documents.manage-general'));
    }

    public function archive(User $user, Document $document): bool
    {
        return $this->view($user, $document)
            && $user->hasPermission('documents.archive')
            && ($document->producing_unit_id !== null || $user->hasPermission('documents.manage-general'));
    }

    public function restore(User $user, Document $document): bool
    {
        return $this->archive($user, $document);
    }

    public function viewAudit(User $user, Document $document): bool
    {
        return $this->view($user, $document)
            && ($user->hasPermission('audit.view') || $user->hasPermission('audit.view-global'));
    }
}
