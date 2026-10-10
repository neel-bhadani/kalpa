<?php

namespace App\Http\Resources;

use App\Models\Asset;
use App\Models\AssetLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A link seen from one asset: "out" when that asset is the source, "in" when it's the target.
 *
 * @mixin AssetLink
 */
class AssetLinkResource extends JsonResource
{
    public function __construct(AssetLink $link, private ?string $viewedFrom = null)
    {
        parent::__construct($link);
    }

    public function toArray(Request $request): array
    {
        $outgoing = $this->viewedFrom === null || $this->from_asset_id === $this->viewedFrom;
        $other = $outgoing ? $this->toAsset : $this->fromAsset;

        return [
            'id' => $this->id,
            'direction' => $outgoing ? 'out' : 'in',
            'relation' => $this->relation,
            'from_asset_id' => $this->from_asset_id,
            'to_asset_id' => $this->to_asset_id,
            'asset' => $other ? ['id' => $other->id, 'name' => $other->name, 'kind' => $other->kind] : null,
            'from_version_id' => $this->from_version_id,
            'data' => $this->data,
            'created_at' => $this->created_at,
        ];
    }

    /**
     * Always wrap in { data }. Laravel skips the wrapper when the resource
     * already has a "data" key, and a link does.
     */
    public function toResponse($request): JsonResponse
    {
        return response()->json(['data' => $this->resolve($request)], $this->resource->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * @param  iterable<AssetLink>  $links
     */
    public static function listFor(Asset $asset, iterable $links): array
    {
        return collect($links)->map(fn (AssetLink $link) => new self($link, $asset->id))->all();
    }
}
