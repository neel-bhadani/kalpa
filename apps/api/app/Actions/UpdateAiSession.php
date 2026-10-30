<?php

namespace App\Actions;

use App\Core\Sync\ChangeRecorder;
use App\Models\AiSession;
use Illuminate\Support\Facades\DB;

class UpdateAiSession
{
    public function __construct(private ChangeRecorder $changes) {}

    /**
     * @param  array{mode?: string, status?: string, summary?: ?string, plan?: object}  $changes
     */
    public function handle(AiSession $session, array $changes): AiSession
    {
        return DB::transaction(function () use ($session, $changes) {
            $session->fill($changes)->save();
            $this->changes->upsert($session);

            return $session;
        });
    }
}
