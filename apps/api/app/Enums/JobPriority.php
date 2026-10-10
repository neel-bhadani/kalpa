<?php

namespace App\Enums;

enum JobPriority: string
{
    case Interactive = 'interactive';
    case Normal = 'normal';
    case Background = 'background';

    /**
     * Claim order: interactive first, background last.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Interactive => 0,
            self::Normal => 1,
            self::Background => 2,
        };
    }
}
