<?php

namespace App\Actions;

use App\Enums\WorkerKind;
use App\Enums\WorkerStatus;
use App\Models\ComputeWorker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PairWorker
{
    /**
     * Trade a pairing code (single use) for a worker and its token. Only the token's hash is stored.
     *
     * @param  list<string>  $capabilities
     * @return array{worker: ComputeWorker, token: string}
     *
     * @throws ValidationException for an unknown or expired code
     */
    public function handle(string $code, string $name, WorkerKind $kind, array $capabilities): array
    {
        $pairing = Cache::pull(CreatePairingCode::cacheKey($code))
            ?? throw ValidationException::withMessages(['code' => 'This pairing code is wrong or has expired. Create a new one in Settings → Workers.']);

        $token = 'kw_'.Str::random(48);

        $worker = ComputeWorker::create([
            'workspace_id' => $pairing['workspace_id'],
            'user_id' => $pairing['user_id'],
            'kind' => $kind,
            'name' => $name,
            'capabilities' => $capabilities,
            'pairing_token_hash' => hash('sha256', $token),
            'status' => WorkerStatus::Online,
            'last_heartbeat_at' => now(),
        ]);

        return ['worker' => $worker, 'token' => $token];
    }
}
