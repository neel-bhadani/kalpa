<?php

namespace App\Core\Workspaces;

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * The workspace of the current request (from the X-Workspace-Id header) and the user's role in it.
 * Set by the EnsureWorkspaceMember middleware; one instance per request.
 */
class CurrentWorkspace
{
    private ?Workspace $workspace = null;

    private ?WorkspaceRole $role = null;

    public function set(Workspace $workspace, WorkspaceRole $role): void
    {
        $this->workspace = $workspace;
        $this->role = $role;
    }

    public function get(): Workspace
    {
        return $this->workspace ?? throw new LogicException('No current workspace; is the workspace middleware on this route?');
    }

    public function id(): string
    {
        return $this->get()->id;
    }

    public function role(): ?WorkspaceRole
    {
        return $this->role;
    }

    /**
     * Whether the model belongs to the current workspace.
     */
    public function contains(Model $model): bool
    {
        return $this->workspace !== null && $model->getAttribute('workspace_id') === $this->workspace->id;
    }

    public function canWrite(): bool
    {
        return $this->role?->canWrite() ?? false;
    }

    public function isOwner(): bool
    {
        return $this->role === WorkspaceRole::Owner;
    }
}
