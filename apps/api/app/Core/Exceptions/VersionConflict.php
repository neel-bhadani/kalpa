<?php

namespace App\Core\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * The client edited an old row_version. Carries the current row so the API can send it back with a 409.
 */
class VersionConflict extends RuntimeException
{
    public function __construct(public readonly Model $current)
    {
        parent::__construct('This was changed somewhere else. Reload the latest version and try again.');
    }
}
