<?php

namespace App\Actions;

use App\Enums\WorkerStatus;
use App\Models\ComputeWorker;

class RemoveWorker
{
    /**
     * Disable the worker and forget its token. Jobs it held go back to the queue when their lease runs out.
     */
    public function handle(ComputeWorker $worker): void
    {
        $worker->forceFill(['status' => WorkerStatus::Disabled, 'pairing_token_hash' => null])->save();
        $worker->delete();
    }
}
