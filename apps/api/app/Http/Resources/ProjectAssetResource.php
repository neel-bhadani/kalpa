<?php

namespace App\Http\Resources;

use App\Models\ProjectAsset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProjectAsset
 */
class ProjectAssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'asset' => new AssetResource($this->asset),
            // Null: the project follows the asset's latest version.
            'pinned_version' => $this->pinnedVersion ? ['id' => $this->pinnedVersion->id, 'number' => $this->pinnedVersion->number] : null,
            'role' => $this->role,
            'created_at' => $this->created_at,
        ];
    }
}
