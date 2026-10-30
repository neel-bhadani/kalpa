<?php

namespace App\Core\Ai;

use App\Enums\AiStepStatus;
use App\Models\AiStep;
use Illuminate\Validation\ValidationException;

/**
 * A change an assistant step makes (an asset version, a project memory patch) names the step.
 * The step must be in the same workspace and running right now.
 */
class AssistantStep
{
    /**
     * @throws ValidationException
     */
    public static function running(?string $stepId, string $workspaceId): ?AiStep
    {
        if ($stepId === null) {
            return null;
        }

        $step = AiStep::where('workspace_id', $workspaceId)->find($stepId);
        if ($step === null || $step->status !== AiStepStatus::Running) {
            throw ValidationException::withMessages(['ai_step_id' => 'That assistant step is not running.']);
        }

        return $step;
    }
}
