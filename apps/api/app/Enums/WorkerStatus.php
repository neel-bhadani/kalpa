<?php

namespace App\Enums;

enum WorkerStatus: string
{
    case Online = 'online';
    case Offline = 'offline';
    case Disabled = 'disabled';
}
