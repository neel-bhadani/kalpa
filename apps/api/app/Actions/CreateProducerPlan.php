<?php

namespace App\Actions;

use App\Core\Sync\ChangeRecorder;
use App\Enums\AiAssistant;
use App\Enums\AiHandoffStatus;
use App\Enums\AiRole;
use App\Enums\AiSessionStatus;
use App\Enums\ComputeTarget;
use App\Enums\TaskMode;
use App\Models\AiHandoff;
use App\Models\AiSession;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateProducerPlan
{
    /**
     * The fake brain's plan: always these three steps, each waiting for the one before.
     * A real Producer (P1) plans from the goal instead.
     */
    private const PLAN = [
        ['assistant' => 'story', 'module' => 'story', 'kind' => 'write_script', 'title' => 'Write the script'],
        ['assistant' => 'character', 'module' => 'character', 'kind' => 'create_character', 'title' => 'Design the main character'],
        ['assistant' => 'voice', 'module' => 'voice', 'kind' => 'record_dialogue', 'title' => 'Record the dialogue'],
    ];

    public function __construct(
        private CreateTask $createTask,
        private AddTaskDependency $addDependency,
        private AddAiMessage $addMessage,
        private ChangeRecorder $changes,
    ) {}

    /**
     * The Producer plans a goal into tasks with dependencies and hands each to its module's assistant.
     * Nothing runs yet: the plan, the tasks and the handoffs are stored.
     *
     * @return array{session: AiSession, tasks: list<Task>, handoffs: list<AiHandoff>}
     */
    public function handle(Project $project, User $user, string $goal): array
    {
        return DB::transaction(function () use ($project, $user, $goal) {
            $session = AiSession::create([
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->id,
                'task_id' => null,
                'module' => 'producer',
                'assistant' => AiAssistant::Producer,
                'mode' => TaskMode::Semi,
                'status' => AiSessionStatus::Done,
                'brain_model_key' => 'fake-brain',
                'compute_target' => ComputeTarget::Server,
                'plan' => ['goal' => $goal, 'steps' => self::PLAN],
                'summary' => 'Planned 3 tasks: script, then the main character, then the dialogue.',
                'created_by' => $user->id,
            ]);

            $this->addMessage->handle($session, AiRole::User, (object) ['text' => $goal]);

            $tasks = [];
            $handoffs = [];
            foreach (self::PLAN as $i => $step) {
                $task = $this->createTask->handle($project, $user, [
                    'module' => $step['module'],
                    'kind' => $step['kind'],
                    'title' => "{$step['title']}: {$goal}",
                    'mode' => TaskMode::Semi->value,
                ]);

                if ($i > 0) {
                    $this->addDependency->handle($task, $tasks[$i - 1], $user);
                }

                $handoffs[] = AiHandoff::create([
                    'workspace_id' => $project->workspace_id,
                    'from_session_id' => $session->id,
                    'task_id' => $task->id,
                    'request' => ['assistant' => $step['assistant'], 'instruction' => "{$step['title']} for: {$goal}"],
                    'status' => AiHandoffStatus::Pending,
                ]);
                $tasks[] = $task;
            }

            $this->addMessage->handle($session, AiRole::Assistant, (object) ['text' => $session->summary]);
            $this->changes->upsert($session);

            return ['session' => $session, 'tasks' => $tasks, 'handoffs' => $handoffs];
        });
    }
}
