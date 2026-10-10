<?php

namespace App\Models;

use App\Enums\AiProviderKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['user_id', 'provider', 'name', 'base_url', 'api_key', 'models', 'enabled'])]
#[Hidden(['api_key'])]
class AiProviderConfig extends Model
{
    use HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'provider' => AiProviderKind::class,
            // Stored encrypted with the app key.
            'api_key' => 'encrypted',
            'models' => 'array',
            'enabled' => 'boolean',
        ];
    }

    /**
     * The key's last 4 characters, the only part ever shown.
     */
    public function keyHint(): ?string
    {
        return $this->api_key ? '…'.substr($this->api_key, -4) : null;
    }
}
