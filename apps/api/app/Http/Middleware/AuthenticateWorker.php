<?php

namespace App\Http\Middleware;

use App\Core\Workspaces\CurrentWorkspace;
use App\Enums\WorkerStatus;
use App\Models\ComputeWorker;
use App\Models\WorkspaceMember;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who is calling the worker endpoints (claim, heartbeat, complete, fail):
 * - a paired worker, with "Authorization: Bearer kw_..." (its token), or
 * - a browser tab, with the user's session or token plus "X-Worker-Id".
 * Either way the worker's user must be an editor or owner in the worker's workspace.
 * The worker is put on the request as the "worker" attribute.
 */
class AuthenticateWorker
{
    public function __construct(private CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();

        if (str_starts_with($token, 'kw_')) {
            $worker = ComputeWorker::where('pairing_token_hash', hash('sha256', $token))->first();
            abort_if($worker === null || $worker->status === WorkerStatus::Disabled, 401, 'Unknown or removed worker token.');
            $user = $worker->user;
        } else {
            $user = Auth::guard('sanctum')->user();
            abort_if($user === null, 401, 'Unauthenticated.');

            $workerId = (string) $request->header('X-Worker-Id');
            abort_unless(Str::isUuid($workerId), 400, 'The X-Worker-Id header is missing or not a UUID.');

            $worker = ComputeWorker::whereKey($workerId)->where('user_id', $user->id)->first();
            abort_if($worker === null, 403, 'This is not one of your workers.');
        }

        $membership = WorkspaceMember::with('workspace')
            ->where('workspace_id', $worker->workspace_id)
            ->where('user_id', $user->id)
            ->first();
        abort_unless($membership !== null && $membership->role->canWrite(), 403, 'This worker may not run jobs in its workspace.');

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $this->current->set($membership->workspace, $membership->role);
        $request->attributes->set('worker', $worker);

        return $next($request);
    }
}
