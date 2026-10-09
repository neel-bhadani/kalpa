<?php

namespace App\Enums;

/**
 * Who made a change: a person, an AI assistant, or the system itself.
 */
enum ActorType: string
{
    case User = 'user';
    case Assistant = 'assistant';
    case System = 'system';
}
