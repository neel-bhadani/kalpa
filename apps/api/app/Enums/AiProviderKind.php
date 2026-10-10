<?php

namespace App\Enums;

enum AiProviderKind: string
{
    case OpenAiCompatible = 'openai_compatible';
    case Ollama = 'ollama';
}
