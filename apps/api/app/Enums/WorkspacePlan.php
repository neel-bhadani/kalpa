<?php

namespace App\Enums;

enum WorkspacePlan: string
{
    case Free = 'free';
    case Pro = 'pro';
    case Team = 'team';
}
