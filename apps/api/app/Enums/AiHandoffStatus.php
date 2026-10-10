<?php

namespace App\Enums;

enum AiHandoffStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Done = 'done';
    case Failed = 'failed';
}
