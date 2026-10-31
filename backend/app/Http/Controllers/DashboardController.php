<?php

namespace App\Http\Controllers;

use App\Models\DocumentVersion;
use App\Services\DocumentAccess;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DocumentAccess $access)
    {
        $documents = $access->query($request->user());
        $byType = (clone $documents)->selectRaw('document_type_id, COUNT(*) AS total')->groupBy('document_type_id')->with('type:id,name')->get();

        return response()->json(['data' => [
            'total_documents' => (clone $documents)->count(),
            'active_documents' => (clone $documents)->whereNull('archived_at')->count(),
            'archived_documents' => (clone $documents)->whereNotNull('archived_at')->count(),
            'storage_bytes' => (int) DocumentVersion::whereIn('document_id', (clone $documents)->select('id'))->sum('size_bytes'),
            'by_type' => $byType->map(fn ($row) => ['id' => $row->document_type_id, 'name' => $row->type->name, 'total' => (int) $row->total]),
        ]]);
    }
}
