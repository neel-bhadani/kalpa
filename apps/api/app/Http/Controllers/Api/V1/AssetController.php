<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateAsset;
use App\Actions\DeleteAsset;
use App\Actions\UpdateAsset;
use App\Core\Workspaces\CurrentWorkspace;
use App\Enums\AssetFileRole;
use App\Enums\AssetOrigin;
use App\Http\Controllers\Controller;
use App\Http\Resources\AssetDetailResource;
use App\Http\Resources\AssetResource;
use App\Models\Asset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AssetController extends Controller
{
    /**
     * ?kind=&module=&project=&tag=&q= (q matches words in the name and tags, as prefixes).
     */
    public function index(Request $request, CurrentWorkspace $current): AnonymousResourceCollection
    {
        $request->validate([
            'kind' => ['sometimes', 'string', 'max:64'],
            'module' => ['sometimes', 'string', 'max:64'],
            'project' => ['sometimes', 'uuid'],
            'tag' => ['sometimes', 'string', 'max:50'],
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        $assets = Asset::where('workspace_id', $current->id())
            ->when($request->query('kind'), fn ($query, $kind) => $query->where('kind', $kind))
            ->when($request->query('module'), fn ($query, $module) => $query->where('module', $module))
            ->when($request->query('project'), fn ($query, $id) => $query->whereHas('projectAssets', fn ($q) => $q->where('project_id', $id)))
            ->when($request->query('tag'), fn ($query, $tag) => $query->whereRaw('tags @> ARRAY[?]::text[]', [$tag]))
            ->when($this->searchQuery((string) $request->query('q')), fn ($query, $tsquery) => $query->whereRaw("search @@ to_tsquery('simple', ?)", [$tsquery]))
            ->with('currentVersion.assetFiles.file.thumb.file')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->cursorPaginate($this->perPage($request));

        return AssetResource::collection($assets);
    }

    public function store(Request $request, CurrentWorkspace $current, CreateAsset $createAsset): JsonResponse
    {
        Gate::authorize('create', Asset::class);

        $data = $request->validate([
            'kind' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'spec' => ['present'],
            'origin' => ['sometimes', Rule::enum(AssetOrigin::class)],
            'tags' => ['sometimes', 'array', 'list', 'max:20'],
            'tags.*' => ['string', 'max:50', 'distinct'],
            'licence' => ['sometimes', 'nullable', 'string', 'max:255'],
            'attribution' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'project_id' => ['sometimes', 'nullable', 'uuid'],
            'task_id' => ['sometimes', 'nullable', 'uuid'],
            'files' => ['sometimes', 'array', 'list'],
            'files.*.file_id' => ['required', 'uuid'],
            'files.*.role' => ['required', Rule::enum(AssetFileRole::class)],
        ]);

        $data['spec'] = $this->objectSpec($request);

        $asset = $createAsset->handle($current->get(), $request->user(), $data);

        return (new AssetDetailResource($this->loadDetail($asset)))->response()->setStatusCode(201);
    }

    public function show(Asset $asset): AssetDetailResource
    {
        Gate::authorize('view', $asset);

        return new AssetDetailResource($this->loadDetail($asset));
    }

    public function update(Request $request, Asset $asset, UpdateAsset $updateAsset): AssetDetailResource
    {
        Gate::authorize('update', $asset);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'tags' => ['sometimes', 'array', 'list', 'max:20'],
            'tags.*' => ['string', 'max:50', 'distinct'],
            'licence' => ['sometimes', 'nullable', 'string', 'max:255'],
            'attribution' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        return new AssetDetailResource($this->loadDetail($updateAsset->handle($asset, $data)));
    }

    public function destroy(Asset $asset, DeleteAsset $deleteAsset): Response
    {
        Gate::authorize('delete', $asset);

        $deleteAsset->handle($asset);

        return response()->noContent();
    }

    private function loadDetail(Asset $asset): Asset
    {
        return $asset->load(['currentVersion.assetFiles.file.thumb.file', 'projectAssets.project']);
    }

    /**
     * The spec as decoded objects, so {} stays {} for JSON Schema validation.
     */
    private function objectSpec(Request $request): object
    {
        $spec = $this->jsonBody($request)->spec ?? null;

        if (! is_object($spec)) {
            throw ValidationException::withMessages(['spec' => 'The spec must be a JSON object.']);
        }

        return $spec;
    }

    /**
     * Words of the search box as a prefix tsquery: "red car" → "red:* & car:*".
     */
    private function searchQuery(string $q): ?string
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($q), $words);

        return $words[0] ? implode(' & ', array_map(fn (string $word) => "{$word}:*", $words[0])) : null;
    }
}
