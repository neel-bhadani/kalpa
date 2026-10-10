<?php

namespace App\Policies;

use App\Core\Workspaces\CurrentWorkspace;
use App\Models\AiSession;
use App\Models\User;

/**
 * Members see assistant sessions; editors and owners talk to the assistant and act on its steps.
 */
class AiSessionPolicy
{
    public function __construct(private CurrentWorkspace $current) {}

    public function view(User $user, AiSession $session): bool
    {
        return $this->current->contains($session);
    }

    public function update(User $user, AiSession $session): bool
    {
        return $this->current->contains($session) && $this->current->canWrite();
    }
}
