<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_id', 'module', 'enabled', 'settings'])]
class ModuleSetting extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'settings' => 'object',
        ];
    }
}
