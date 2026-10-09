<?php

namespace App\Policies;

use App\Core\Workspaces\CurrentWorkspace;
use App\Models\File;
use App\Models\User;

/**
 * Any member of the file's workspace may read it.
 */
class FilePolicy
{
    public function __construct(private CurrentWorkspace $current) {}

    public function view(User $user, File $file): bool
    {
        return $this->current->contains($file);
    }
}
