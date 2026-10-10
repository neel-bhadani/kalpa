<?php

namespace App\Enums;

enum AiStepStatus: string
{
    case Planned = 'planned';
    case AwaitingReview = 'awaiting_review';
    case Running = 'running';
    case Done = 'done';
    case Rejected = 'rejected';
    case Undone = 'undone';
    case Failed = 'failed';

    /**
     * The statuses a step can move to from this one.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Planned => [self::AwaitingReview, self::Running, self::Rejected],
            self::AwaitingReview => [self::Running, self::Rejected],
            self::Running => [self::Done, self::Failed],
            self::Done => [self::Undone],
            self::Failed => [self::Running], // retry
            self::Rejected, self::Undone => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }
}
