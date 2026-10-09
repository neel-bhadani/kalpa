<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\PatchProjectMemory;
use App\Core\Exceptions\VersionConflict;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectMemoryResource;
use App\Http\Resources\ProjectMemoryVersionResource;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ProjectMemoryController extends Controller
{
    public function show(Project $project): ProjectMemoryResource
    {
        Gate::authorize('view', $project);

        return new ProjectMemoryResource($project->memory);
    }

    /**
     * Body: { base_version, patch: [RFC 6902 operations] }. A stale base_version gets 409 and the current memory.
     */
    public function update(Request $request, Project $project, PatchProjectMemory $patchMemory): ProjectMemoryResource|JsonResponse
    {
        Gate::authorize('update', $project);

        $request->validate([
            'base_version' => ['required', 'integer'],
            'patch' => ['required', 'array', 'list', 'min:1'],
            'patch.*.op' => ['required', 'in:add,remove,replace,move,copy,test'],
            'patch.*.path' => ['present'],
        ]);

        try {
            $memory = $patchMemory->handle(
                $project->memory,
                $request->integer('base_version'),
                $this->jsonBody($request)->patch,
                $request->user(),
            );
        } catch (VersionConflict $conflict) {
            return response()->json([
                'message' => $conflict->getMessage(),
                'data' => new ProjectMemoryResource($conflict->current),
            ], 409);
        }

        return new ProjectMemoryResource($memory);
    }

    public function versions(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('view', $project);

        $versions = $project->memory->versions()
            ->orderByDesc('number')
            ->cursorPaginate($this->perPage($request));

        return ProjectMemoryVersionResource::collection($versions);
    }
}
