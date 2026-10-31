<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListRequest;
use App\Models\Permission;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class PermissionController extends Controller
{
    public function index(ListRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $query = Permission::query();
        if (isset($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(fn ($query) => $query->where('name', 'ilike', $search)->orWhere('key', 'ilike', $search));
        }
        if (isset($filters['group'])) {
            $query->where('group', $filters['group']);
        }

        return JsonResource::collection($query->orderBy('key')->paginate($filters['per_page'] ?? 20));
    }

    public function show(Permission $permission): JsonResource
    {
        return new JsonResource($permission);
    }
}
