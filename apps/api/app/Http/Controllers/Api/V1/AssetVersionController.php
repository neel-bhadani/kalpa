<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateAssetVersion;
use App\Actions\RestoreAssetVersion;
use App\Core\Exceptions\VersionConflict;
use App\Enums\AssetFileRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\AssetVersionResource;
use App\Models\Asset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AssetVersionController extends Controller
{
    public function index(Request $request, Asset $asset): AnonymousResourceCollection
    {
        Gate::authorize('view', $asset);

        $versions = $asset->versions()
            ->with(['creator', 'assetFiles'])
            ->orderByDesc('number')
            ->cursorPaginate($this->perPage($request));

        return AssetVersionResource::collection($versions);
    }

    public function show(Asset $asset, int $number): AssetVersionResource
    {
        Gate::authorize('view', $asset);

        return new AssetVersionResource($asset->versions()->with(['creator', 'assetFiles'])->where('number', $number)->firstOrFail());
    }

    /**
     * Body: { base_version, patch: [RFC 6902] | spec: {...}, message?, files?: [{file_id, role}] }.
     * A stale base_version gets 409 and the current version.
     */
    public function store(Request $request, Asset $asset, CreateAssetVersion $createVersion): AssetVersionResource|JsonResponse
    {
        Gate::authorize('update', $asset);

        $request->validate([
            'base_version' => ['required', 'integer'],
            'patch' => ['required_without:spec', 'prohibits:spec', 'array', 'list', 'min:1'],
            'patch.*.op' => ['required', 'in:add,remove,replace,move,copy,test'],
            'patch.*.path' => ['present'],
            'spec' => ['required_without:patch'],
            'message' => ['sometimes', 'nullable', 'string', 'max:500'],
            'files' => ['sometimes', 'array', 'list'],
            'files.*.file_id' => ['required', 'uuid'],
            'files.*.role' => ['required', Rule::enum(AssetFileRole::class)],
        ]);

        $body = $this->jsonBody($request);
        if (isset($body->spec) && ! is_object($body->spec)) {
            throw ValidationException::withMessages(['spec' => 'The spec must be a JSON object.']);
        }

        try {
            $version = $createVersion->handle(
                $asset,
                $request->user(),
                $request->integer('base_version'),
                $body->patch ?? null,
                $body->spec ?? null,
                $request->input('message'),
                $request->input('files'),
            );
        } catch (VersionConflict $conflict) {
            return response()->json([
                'message' => $conflict->getMessage(),
                'data' => new AssetVersionResource($conflict->current->load(['creator', 'assetFiles'])),
            ], 409);
        }

        return new AssetVersionResource($version->load(['creator', 'assetFiles']));
    }

    public function restore(Request $request, Asset $asset, int $number, RestoreAssetVersion $restore): AssetVersionResource
    {
        Gate::authorize('update', $asset);

        return new AssetVersionResource($restore->handle($asset, $request->user(), $number)->load(['creator', 'assetFiles']));
    }
}
