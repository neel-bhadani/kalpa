<?php

namespace App\Core\Jobs;

use App\Enums\JobLogLevel;
use App\Models\Job;
use App\Models\JobLog;

class JobLogger
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function log(Job $job, string $message, JobLogLevel $level = JobLogLevel::Info, array $data = []): JobLog
    {
        return JobLog::create([
            'workspace_id' => $job->workspace_id,
            'job_id' => $job->id,
            'level' => $level,
            'message' => $message,
            'data' => (object) $data,
        ]);
    }
}
