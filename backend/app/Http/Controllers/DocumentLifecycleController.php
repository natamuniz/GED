<?php

namespace App\Http\Controllers;

use App\Http\Requests\MoveDocumentRequest;
use App\Http\Requests\UpdateDocumentMetadataRequest;
use App\Models\Document;
use App\Services\DocumentLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentLifecycleController extends Controller
{
    public function update(UpdateDocumentMetadataRequest $request, Document $document, DocumentLifecycle $lifecycle): JsonResponse
    {
        return $this->respond($lifecycle->updateMetadata($request, $document));
    }

    public function move(MoveDocumentRequest $request, Document $document, DocumentLifecycle $lifecycle): JsonResponse
    {
        return $this->respond($lifecycle->move($request, $document));
    }

    public function archive(Request $request, Document $document, DocumentLifecycle $lifecycle): JsonResponse
    {
        return $this->respond($lifecycle->archive($request->user(), $document));
    }

    public function restore(Request $request, Document $document, DocumentLifecycle $lifecycle): JsonResponse
    {
        return $this->respond($lifecycle->restore($request->user(), $document));
    }

    private function respond(Document $document): JsonResponse
    {
        return response()->json(['data' => $document])->header('Cache-Control', 'private, no-store');
    }
}
