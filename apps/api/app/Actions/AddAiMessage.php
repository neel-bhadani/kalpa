<?php

namespace App\Actions;

use App\Enums\AiRole;
use App\Models\AiMessage;
use App\Models\AiSession;

class AddAiMessage
{
    /**
     * Append a message (user text, the assistant's reply and tool calls...). Messages are never changed.
     */
    public function handle(AiSession $session, AiRole $role, object $content, ?int $tokens = null): AiMessage
    {
        return AiMessage::create([
            'workspace_id' => $session->workspace_id,
            'session_id' => $session->id,
            'role' => $role,
            'content' => $content,
            'tokens' => $tokens,
        ]);
    }
}
