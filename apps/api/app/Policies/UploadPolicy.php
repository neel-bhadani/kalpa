<?php

namespace App\Policies;

use App\Core\Workspaces\CurrentWorkspace;
use App\Models\Upload;
use App\Models\User;

/**
 * Editors and owners upload. An upload in progress belongs to the person who started it.
 */
class UploadPolicy
{
    public function __construct(private CurrentWorkspace $current) {}

    public function create(User $user): bool
    {
        return $this->current->canWrite();
    }

    public function update(User $user, Upload $upload): bool
    {
        return $this->current->contains($upload) && $this->current->canWrite() && $upload->user_id === $user->id;
    }
}
