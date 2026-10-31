<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListAuditEventsRequest;
use App\Http\Resources\AuditEventResource;
use App\Models\Document;
use App\Models\User;
use App\Services\AuditAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class AuditEventController extends Controller
{
    public function index(ListAuditEventsRequest $request, AuditAccess $access): JsonResponse
    {
        return $access->read($request->user(), function (User $actor) use ($request, $access) {
            $filters = $request->validated();
            $query = $access->filter($access->query($actor), $filters)->orderByDesc('created_at')->orderByDesc('id');

            return $this->privateResponse(AuditEventResource::collection($query->paginate($filters['per_page'] ?? 20)->withQueryString())->response());
        });
    }

    public function document(ListAuditEventsRequest $request, Document $document, AuditAccess $access): JsonResponse
    {
        return $access->read($request->user(), function (User $actor) use ($request, $document, $access) {
            $document = $document->fresh();
            Gate::forUser($actor)->authorize('viewAudit', $document);
            $filters = $request->validated();
            $query = $access->filter($access->query($actor)->where('document_id', $document->id), $filters)
                ->orderByDesc('created_at')->orderByDesc('id');

            return $this->privateResponse(AuditEventResource::collection($query->paginate($filters['per_page'] ?? 20)->withQueryString())->response());
        });
    }

    public function show(Request $request, string $event, AuditAccess $access): JsonResponse
    {
        return $access->read($request->user(), function (User $actor) use ($event, $access) {
            $data = Validator::make(['event' => $event], ['event' => ['required', 'integer', 'min:1']])->validate();

            return $this->privateResponse((new AuditEventResource($access->query($actor)->findOrFail($data['event'])))->response());
        });
    }

    private function privateResponse(JsonResponse $response): JsonResponse
    {
        return $response->header('Cache-Control', 'private, no-store');
    }
}
