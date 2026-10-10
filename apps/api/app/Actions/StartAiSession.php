<?php

namespace App\Actions;

use App\Core\Sync\ChangeRecorder;
use App\Enums\AiAssistant;
use App\Enums\AiSessionStatus;
use App\Enums\ComputeTarget;
use App\Enums\ModelCapability;
use App\Enums\TaskMode;
use App\Models\AiSession;
use App\Models\RegisteredModel;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StartAiSession
{
    public function __construct(private ChangeRecorder $changes) {}

    /**
     * Open a conversation with the task module's assistant (the Producer for modules without one).
     *
     * @throws ValidationException when the brain model isn't a registered, enabled LLM
     */
    public function handle(Task $task, User $user, ?TaskMode $mode = null, string $brain = 'fake-brain'): AiSession
    {
        $model = RegisteredModel::where('key', $brain)->first();
        if ($model === null || ! $model->enabled || $model->capability !== ModelCapability::Llm) {
            throw ValidationException::withMessages(['brain_model_key' => "\"{$brain}\" is not an enabled language model."]);
        }

        return DB::transaction(function () use ($task, $user, $mode, $model) {
            $session = AiSession::create([
                'workspace_id' => $task->workspace_id,
                'project_id' => $task->project_id,
                'task_id' => $task->id,
                'module' => $task->module,
                'assistant' => AiAssistant::tryFrom($task->module) ?? AiAssistant::Producer,
                'mode' => $mode ?? $task->mode,
                'status' => AiSessionStatus::Active,
                'brain_model_key' => $model->key,
                // The fake brain runs in the browser; later models choose by device tier.
                'compute_target' => ComputeTarget::Device,
                'created_by' => $user->id,
            ])->refresh();

            $this->changes->upsert($session);

            return $session;
        });
    }
}
