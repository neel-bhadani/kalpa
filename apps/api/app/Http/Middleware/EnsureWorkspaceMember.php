<?php

namespace App\Http\Middleware;

use App\Core\Workspaces\CurrentWorkspace;
use App\Models\WorkspaceMember;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Workspace-scoped routes need an X-Workspace-Id header for a workspace the user belongs to.
 */
class EnsureWorkspaceMember
{
    public function __construct(private CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $workspaceId = (string) $request->header('X-Workspace-Id');

        abort_unless(Str::isUuid($workspaceId), 400, 'The X-Workspace-Id header is missing or not a UUID.');

        $membership = WorkspaceMember::with('workspace')
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $request->user()->id)
            ->first();

        abort_if($membership === null || $membership->workspace === null, 403, 'You are not a member of this workspace.');

        $this->current->set($membership->workspace, $membership->role);

        return $next($request);
    }
}
