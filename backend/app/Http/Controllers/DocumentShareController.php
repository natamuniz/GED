<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentShareRequest;
use App\Http\Resources\DocumentShareResource;
use App\Models\DocumentShare;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\DocumentSharing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentShareController extends Controller
{
    public function store(StoreDocumentShareRequest $request, DocumentVersion $version, DocumentSharing $sharing): JsonResponse
    {
        $result = $sharing->create($request, $version);

        return $this->privateResponse((new DocumentShareResource($result['share']))->additional([
            'token' => $result['token'],
            'access_url' => route('shared.show', ['token' => $result['token']]),
            'download_url' => route('shared.download', ['token' => $result['token']]),
        ])->response());
    }

    public function index(Request $request, DocumentVersion $version, DocumentSharing $sharing): JsonResponse
    {
        $pageSize = $this->pageSize($request);

        return $sharing->read($request->user(), function (User $actor) use ($version, $pageSize) {
            $version = $version->fresh('document');
            Gate::forUser($actor)->authorize('view', $version->document);
            $managesAll = $actor->hasPermission('shares.revoke-any');
            abort_unless($managesAll || $actor->hasPermission('shares.create') || $actor->hasPermission('shares.revoke-own'), 403);
            $query = DocumentShare::where('document_version_id', $version->id)->with(DocumentSharing::RELATIONS);
            if (! $managesAll) {
                $query->where('created_by', $actor->id);
            }

            return $this->privateResponse(DocumentShareResource::collection($query->orderByDesc('id')->paginate($pageSize))->response());
        });
    }

    public function received(Request $request, DocumentSharing $sharing): JsonResponse
    {
        $pageSize = $this->pageSize($request);

        return $sharing->read($request->user(), function (User $actor) use ($pageSize) {
            $query = DocumentShare::whereHas('recipients', fn ($query) => $query->where('users.id', $actor->id))
                ->with(DocumentSharing::RELATIONS)->orderByDesc('id');

            return $this->privateResponse(DocumentShareResource::collection($query->paginate($pageSize))->response());
        });
    }

    public function recipients(Request $request, DocumentSharing $sharing): JsonResponse
    {
        $pageSize = $this->pageSize($request);
        $data = $request->validate(['search' => ['nullable', 'string', 'max:255']]);

        return $sharing->read($request->user(), function (User $actor) use ($pageSize, $data) {
            abort_unless($actor->hasPermission('shares.create'), 403);
            $query = User::where('is_active', true)->select('id', 'name', 'email');
            if (isset($data['search'])) {
                $search = '%'.$data['search'].'%';
                $query->where(fn ($query) => $query->where('name', 'ilike', $search)->orWhere('email', 'ilike', $search));
            }

            return $this->privateResponse(response()->json($query->orderBy('name')->orderBy('id')->paginate($pageSize)));
        });
    }

    public function show(Request $request, DocumentShare $share, DocumentSharing $sharing): JsonResponse
    {
        return $sharing->access($request->user(), $share, 'share.viewed', fn (DocumentShare $share) => $this->privateResponse((new DocumentShareResource($share))->response())
        );
    }

    public function showToken(Request $request, string $token, DocumentSharing $sharing): JsonResponse
    {
        return $this->show($request, $sharing->resolveToken($token), $sharing);
    }

    public function download(Request $request, DocumentShare $share, DocumentSharing $sharing): StreamedResponse
    {
        return $sharing->access($request->user(), $share, 'share.downloaded', function (DocumentShare $share) {
            $version = $share->version;
            $disk = Storage::disk($version->storage_disk);
            abort_unless($disk->exists($version->storage_path), 404, 'Arquivo indisponível.');

            return $disk->download($version->storage_path, $version->original_filename, [
                'Content-Type' => 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
                'Referrer-Policy' => 'no-referrer',
            ]);
        });
    }

    public function downloadToken(Request $request, string $token, DocumentSharing $sharing): StreamedResponse
    {
        return $this->download($request, $sharing->resolveToken($token), $sharing);
    }

    public function revoke(Request $request, DocumentShare $share, DocumentSharing $sharing): JsonResponse
    {
        $share = $sharing->revoke($request->user(), $share);

        return $this->privateResponse(response()->json(['data' => $share->only('id', 'revoked_at', 'revoked_by')]));
    }

    private function pageSize(Request $request): int
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return (int) ($data['per_page'] ?? 20);
    }

    private function privateResponse(JsonResponse $response): JsonResponse
    {
        return $response->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
