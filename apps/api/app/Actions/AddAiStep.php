<?php

namespace App\Actions;

use App\Core\Sync\ChangeRecorder;
use App\Enums\AiStepStatus;
use App\Models\AiSession;
use App\Models\AiStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddAiStep
{
    public function __construct(private ChangeRecorder $changes) {}

    /**
     * A tool call becomes a step, numbered in order: planned (a suggestion), awaiting_review
     * (Semi mode) or running (Auto mode).
     *
     * @throws ValidationException for any other starting status
     */
    public function handle(AiSession $session, string $title, string $tool, object $args, AiStepStatus $status): AiStep
    {
        if (! in_array($status, [AiStepStatus::Planned, AiStepStatus::AwaitingReview, AiStepStatus::Running], true)) {
            throw ValidationException::withMessages(['status' => 'A new step is planned, awaiting_review or running.']);
        }

        return DB::transaction(function () use ($session, $title, $tool, $args, $status) {
            // One step at a time per session, so seq numbers don't clash.
            AiSession::whereKey($session->id)->lockForUpdate()->first();

            $step = AiStep::create([
                'workspace_id' => $session->workspace_id,
                'session_id' => $session->id,
                'seq' => (int) AiStep::where('session_id', $session->id)->max('seq') + 1,
                'title' => $title,
                'tool' => $tool,
                'args' => $args,
                'status' => $status,
            ])->refresh();

            $this->changes->upsert($step);

            return $step;
        });
    }
}
