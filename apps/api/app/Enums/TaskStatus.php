<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Draft = 'draft';
    case InProgress = 'in_progress';
    case WaitingJob = 'waiting_job';
    case NeedsReview = 'needs_review';
    case Paused = 'paused';
    case Failed = 'failed';
    case Completed = 'completed';
    case Archived = 'archived';

    /**
     * The statuses a task can move to from this one.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::InProgress, self::Paused, self::Archived],
            self::InProgress => [self::WaitingJob, self::NeedsReview, self::Paused, self::Failed, self::Completed],
            self::WaitingJob => [self::InProgress, self::NeedsReview, self::Paused, self::Failed],
            self::NeedsReview => [self::InProgress, self::Completed],
            self::Paused => [self::InProgress, self::Archived],
            self::Failed => [self::InProgress, self::Archived],
            self::Completed => [self::InProgress, self::Archived], // reopen, archive
            self::Archived => [self::Paused], // restore
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }
}
