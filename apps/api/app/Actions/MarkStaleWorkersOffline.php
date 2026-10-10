<?php

namespace App\Actions;

use App\Enums\WorkerStatus;
use App\Models\ComputeWorker;

class MarkStaleWorkersOffline
{
    public function handle(): int
    {
        return ComputeWorker::where('status', WorkerStatus::Online)
            ->where('last_heartbeat_at', '<', now()->subSeconds(config('jobs.worker_offline_after_seconds')))
            ->update(['status' => WorkerStatus::Offline]);
    }
}
