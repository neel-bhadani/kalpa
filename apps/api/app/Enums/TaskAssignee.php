<?php

namespace App\Enums;

enum TaskAssignee: string
{
    case User = 'user';
    case Assistant = 'assistant';
}
