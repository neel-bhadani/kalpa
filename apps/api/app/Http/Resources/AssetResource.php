<?php

namespace App\Http\Resources;

use App\Core\Modules\ModuleRegistry;
use App\Core\Storage\ObjectStorage;
use App\Enums\AssetFileRole;
use App\Models\Asset;
use App\Models\AssetFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Asset
 */
class AssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'kind_label' => app(ModuleRegistry::class)->assetKinds()[$this->kind]['label'] ?? $this->kind,
            'module' => $this->module,
            'name' => $this->name,
            'origin' => $this->origin,
            'licence' => $this->licence,
            'attribution' => $this->attribution,
            'tags' => $this->tags,
            'current_version' => $this->whenLoaded('currentVersion', fn () => [
                'id' => $this->currentVersion->id,
                'number' => $this->currentVersion->number,
                'created_at' => $this->currentVersion->created_at,
            ]),
            'thumbnail_url' => $this->thumbnailUrl(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * A short-lived URL for the current version's thumbnail.
     */
    private function thumbnailUrl(): ?string
    {
        if (! $this->relationLoaded('currentVersion') || ! $this->currentVersion->relationLoaded('assetFiles')) {
            return null;
        }

        $files = $this->currentVersion->assetFiles;
        $thumbnail = $files->first(fn (AssetFile $file) => $file->role === AssetFileRole::Thumbnail)?->file;
        $image = $files->first(fn (AssetFile $file) => $file->role === AssetFileRole::Image)?->file;

        // An explicit thumbnail, else the image's generated thumb (file.thumbnail job), else the image itself.
        $pick = $thumbnail ?? ($image?->relationLoaded('thumb') ? $image->thumb?->file : null) ?? $image;

        if ($pick === null) {
            return null;
        }

        return app(ObjectStorage::class)->presignGet(
            $pick->path,
            $pick->mime,
            now()->addMinutes(config('uploads.download_url_minutes')),
        );
    }
}
