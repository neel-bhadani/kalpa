<?php

namespace App\Http\Resources;

use App\Models\AiMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AiMessage
 */
class AiMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'session_id' => $this->session_id,
            'role' => $this->role,
            'content' => $this->content,
            'tokens' => $this->tokens,
            'created_at' => $this->created_at,
        ];
    }
}
