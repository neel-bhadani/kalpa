<?php

namespace App\Policies;

use App\Core\Workspaces\CurrentWorkspace;
use App\Models\Asset;
use App\Models\User;

/**
 * Viewers read assets; editors and owners create, version, link and delete them.
 * Assets outside the current workspace are always refused.
 */
class AssetPolicy
{
    public function __construct(private CurrentWorkspace $current) {}

    public function view(User $user, Asset $asset): bool
    {
        return $this->current->contains($asset);
    }

    public function create(User $user): bool
    {
        return $this->current->canWrite();
    }

    public function update(User $user, Asset $asset): bool
    {
        return $this->current->contains($asset) && $this->current->canWrite();
    }

    public function delete(User $user, Asset $asset): bool
    {
        return $this->current->contains($asset) && $this->current->canWrite();
    }
}
