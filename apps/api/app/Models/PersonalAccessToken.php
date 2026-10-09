<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum's token model with UUIDv7 ids, like every other table.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use HasUuids;
}
