<?php

namespace App\Actions;

use App\Enums\WorkerKind;
use App\Enums\WorkerStatus;
use App\Models\ComputeWorker;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\Workspace;
use Illuminate\Validation\ValidationException;

class RegisterBrowserWorker
{
    /**
     * The browser tab's worker for this device and workspace (no pairing: it uses the user's session).
     *
     * @param  list<string>  $capabilities
     *
     * @throws ValidationException when the device isn't registered (POST devices first)
     */
    public function handle(User $user, Workspace $workspace, string $clientId, array $capabilities): ComputeWorker
    {
        $device = UserDevice::where('user_id', $user->id)->where('client_id', $clientId)->first()
            ?? throw ValidationException::withMessages(['client_id' => 'Register this device first (POST /api/v1/devices).']);

        $worker = ComputeWorker::withTrashed()->firstOrNew([
            'workspace_id' => $workspace->id,
            'user_device_id' => $device->id,
            'kind' => WorkerKind::Browser,
        ]);

        $worker->fill([
            'user_id' => $user->id,
            'name' => $device->name,
            'capabilities' => $capabilities,
            'status' => WorkerStatus::Online,
            'last_heartbeat_at' => now(),
        ]);
        $worker->deleted_at = null;
        $worker->save();

        return $worker;
    }
}
