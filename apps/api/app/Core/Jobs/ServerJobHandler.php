<?php

namespace App\Core\Jobs;

use App\Models\Job;

/**
 * Runs one server-target job type inside RunServerJob. Returns the job's output.
 * Throw to fail the attempt; RunServerJob retries up to max_attempts.
 */
interface ServerJobHandler
{
    /**
     * @return array<string, mixed>
     */
    public function handle(Job $job): array;
}
