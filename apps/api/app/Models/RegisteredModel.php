<?php

namespace App\Models;

use App\Core\Database\TextArray;
use App\Enums\DeviceTier;
use App\Enums\ModelCapability;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A row of model_registry: a model Kalpa knows about, where it can run and whether it may be used.
 */
#[Fillable(['key', 'capability', 'engine', 'display_name', 'licence', 'commercial_ok', 'size_mb', 'min_tier', 'targets', 'source_url', 'sha256', 'builds', 'enabled'])]
class RegisteredModel extends Model
{
    use HasUuids;

    protected $table = 'model_registry';

    protected function casts(): array
    {
        return [
            'capability' => ModelCapability::class,
            'min_tier' => DeviceTier::class,
            'targets' => TextArray::class,
            'commercial_ok' => 'boolean',
            'enabled' => 'boolean',
            'size_mb' => 'integer',
            'builds' => 'array',
        ];
    }
}
