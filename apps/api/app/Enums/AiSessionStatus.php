<?php

namespace App\Enums;

enum AiSessionStatus: string
{
    case Active = 'active';
    case WaitingUser = 'waiting_user';
    case Done = 'done';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
