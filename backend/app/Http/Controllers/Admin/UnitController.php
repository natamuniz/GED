<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListRequest;
use App\Http\Requests\Admin\UnitRequest;
use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Services\Administration;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Validator;

class UnitController extends Controller
{
    public function index(ListRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $query = OrganizationalUnit::with('parent');
        if (isset($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(fn ($query) => $query->where('name', 'ilike', $search)->orWhere('code', 'ilike', $search));
        }
        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return JsonResource::collection($query->orderBy('id')->paginate($filters['per_page'] ?? 20));
    }

    public function show(OrganizationalUnit $unit): JsonResource
    {
        return new JsonResource($unit->load('parent', 'children'));
    }

    public function store(UnitRequest $request, Administration $administration): JsonResource
    {
        return $this->save($request, $administration);
    }

    public function update(UnitRequest $request, OrganizationalUnit $unit, Administration $administration): JsonResource
    {
        return $this->save($request, $administration, $unit);
    }

    private function save(UnitRequest $request, Administration $administration, ?OrganizationalUnit $target = null): JsonResource
    {
        $unit = $administration->mutate($request->user(), 'units.manage', function (User $actor) use ($request, $administration, $target) {
            $data = Validator::make($request->all(), $request->rules())->validate();
            $unit = $target ? OrganizationalUnit::findOrFail($target->id) : new OrganizationalUnit;
            $before = $target ? $unit->only('name', 'code', 'parent_id', 'is_active') : [];
            $unit->fill($data);
            $unit->save();
            $unit->refresh();
            $administration->audit($actor, $target ? 'unit.updated' : 'unit.created', $unit, $before, $unit->only('name', 'code', 'parent_id', 'is_active'));

            return $unit;
        });

        return new JsonResource($unit->load('parent', 'children'));
    }
}
