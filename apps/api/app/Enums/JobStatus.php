<?php

namespace App\Enums;

enum JobStatus: string
{
    case Queued = 'queued';
    case Assigned = 'assigned';
    case Running = 'running';
    case Paused = 'paused';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Not finished yet.
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Queued, self::Assigned, self::Running, self::Paused];
    }

    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }
}
