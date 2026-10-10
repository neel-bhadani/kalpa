<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AttachProjectAsset;
use App\Actions\DetachProjectAsset;
use App\Core\Workspaces\CurrentWorkspace;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectAssetResource;
use App\Models\Asset;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The assets of a project. POST attaches (or re-pins), DELETE detaches; body { asset_id, ... }.
 */
class ProjectAssetController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $items = $project->projectAssets()
            ->whereHas('asset')
            ->with(['asset.currentVersion.assetFiles.file.thumb.file', 'pinnedVersion'])
            ->orderBy('created_at')
            ->get();

        return response()->json(['data' => ProjectAssetResource::collection($items)]);
    }

    public function store(Request $request, Project $project, CurrentWorkspace $current, AttachProjectAsset $attach): ProjectAssetResource
    {
        Gate::authorize('update', $project);

        $data = $request->validate([
            'asset_id' => ['required', 'uuid'],
            'pinned_version_id' => ['sometimes', 'nullable', 'uuid'],
            'role' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $asset = Asset::where('workspace_id', $current->id())->findOrFail($data['asset_id']);
        $item = $attach->handle($project, $asset, $data['pinned_version_id'] ?? null, $data['role'] ?? null);

        return new ProjectAssetResource($item->load(['asset.currentVersion', 'pinnedVersion']));
    }

    public function destroy(Request $request, Project $project, DetachProjectAsset $detach): Response
    {
        Gate::authorize('update', $project);

        $data = $request->validate(['asset_id' => ['required', 'uuid']]);

        $detach->handle($project, $data['asset_id']);

        return response()->noContent();
    }
}
