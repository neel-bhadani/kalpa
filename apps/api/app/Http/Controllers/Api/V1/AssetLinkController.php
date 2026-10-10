<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AddAssetLink;
use App\Actions\RemoveAssetLink;
use App\Core\Workspaces\CurrentWorkspace;
use App\Enums\AssetRelation;
use App\Http\Controllers\Controller;
use App\Http\Resources\AssetLinkResource;
use App\Models\Asset;
use App\Models\AssetLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Links to and from an asset. POST/DELETE body: { to_asset_id, relation, ... }.
 */
class AssetLinkController extends Controller
{
    public function index(Asset $asset): JsonResponse
    {
        Gate::authorize('view', $asset);

        $links = AssetLink::where('from_asset_id', $asset->id)
            ->orWhere('to_asset_id', $asset->id)
            ->with(['fromAsset', 'toAsset'])
            ->orderBy('created_at')
            ->get();

        return response()->json(['data' => AssetLinkResource::listFor($asset, $links)]);
    }

    public function store(Request $request, Asset $asset, CurrentWorkspace $current, AddAssetLink $addLink): JsonResponse
    {
        Gate::authorize('update', $asset);

        $data = $request->validate([
            'to_asset_id' => ['required', 'uuid'],
            'relation' => ['required', Rule::enum(AssetRelation::class)],
            'from_version_id' => ['sometimes', 'nullable', 'uuid'],
            'data' => ['sometimes', 'array'],
        ]);

        $to = Asset::where('workspace_id', $current->id())->findOrFail($data['to_asset_id']);
        $link = $addLink->handle($asset, $to, $data['relation'], $data['from_version_id'] ?? null, $this->jsonBody($request)->data ?? null);

        return (new AssetLinkResource($link->load(['fromAsset', 'toAsset']), $asset->id))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, Asset $asset, RemoveAssetLink $removeLink): Response
    {
        Gate::authorize('update', $asset);

        $data = $request->validate([
            'to_asset_id' => ['required', 'uuid'],
            'relation' => ['required', Rule::enum(AssetRelation::class)],
        ]);

        $removeLink->handle($asset, $data['to_asset_id'], $data['relation']);

        return response()->noContent();
    }
}
