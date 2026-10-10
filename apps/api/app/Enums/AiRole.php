<?php

namespace App\Enums;

enum AiRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
    case Tool = 'tool';
    case System = 'system';
}
