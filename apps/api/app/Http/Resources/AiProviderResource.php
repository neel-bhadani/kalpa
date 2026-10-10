<?php

namespace App\Http\Resources;

use App\Models\AiProviderConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The key itself is never sent, only its last 4 characters.
 *
 * @mixin AiProviderConfig
 */
class AiProviderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'name' => $this->name,
            'base_url' => $this->base_url,
            'api_key_hint' => $this->keyHint(),
            'models' => $this->models ?? [],
            'enabled' => $this->enabled,
            'created_at' => $this->created_at,
        ];
    }
}
