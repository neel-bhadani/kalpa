<?php

namespace App\Http\Resources;

use App\Models\AssetFile;
use App\Models\AssetVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AssetVersion
 */
class AssetVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'parent_version_id' => $this->parent_version_id,
            'schema_version' => $this->schema_version,
            // Specs can be large: in lists only the summary, in full on single-version responses.
            'spec' => $this->when($request->route('number') !== null || $request->isMethod('POST'), fn () => $this->spec),
            'patch' => $this->patch,
            'message' => $this->message,
            'created_by_type' => $this->created_by_type,
            'created_by_id' => $this->created_by_id,
            'ai_step_id' => $this->ai_step_id,
            'author' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'files' => $this->whenLoaded('assetFiles', fn () => $this->assetFiles->map(fn (AssetFile $assetFile) => [
                'role' => $assetFile->role,
                'file_id' => $assetFile->file_id,
            ])),
            'created_at' => $this->created_at,
        ];
    }
}
