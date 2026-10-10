<?php

namespace App\Http\Resources;

use App\Models\RegisteredModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RegisteredModel
 */
class RegisteredModelResource extends JsonResource
{
    public function __construct(RegisteredModel $model, private ?bool $fitsDevice = null)
    {
        parent::__construct($model);
    }

    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'display_name' => $this->display_name,
            'capability' => $this->capability,
            'engine' => $this->engine,
            'licence' => $this->licence,
            'commercial_ok' => $this->commercial_ok,
            'size_mb' => $this->size_mb,
            'min_tier' => $this->min_tier,
            'targets' => $this->targets,
            'source_url' => $this->source_url,
            'enabled' => $this->enabled,
            // Null when the request doesn't say which device it comes from.
            'fits_device' => $this->fitsDevice,
        ];
    }
}
