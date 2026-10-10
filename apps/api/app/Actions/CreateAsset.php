<?php

namespace App\Actions;

use App\Core\Assets\AssetVersionWriter;
use App\Core\Modules\ModuleRegistry;
use App\Core\Sync\ChangeRecorder;
use App\Enums\AssetOrigin;
use App\Enums\TaskOutputRole;
use App\Models\Asset;
use App\Models\Project;
use App\Models\ProjectAsset;
use App\Models\Task;
use App\Models\TaskOutput;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateAsset
{
    public function __construct(
        private ModuleRegistry $modules,
        private AssetVersionWriter $versions,
        private ChangeRecorder $changes,
    ) {}

    /**
     * Create an asset with version 1; optionally add it to a project and as a task output.
     *
     * @param  array{kind: string, name: string, spec: object, origin?: string, tags?: list<string>, licence?: ?string,
     *               attribution?: ?string, project_id?: ?string, task_id?: ?string,
     *               files?: list<array{role: string, file_id: string}>}  $data
     *
     * @throws ValidationException
     */
    public function handle(Workspace $workspace, User $user, array $data): Asset
    {
        $owner = $this->modules->ensureAssetKind($workspace->id, $data['kind']);
        $project = $this->find(Project::class, $workspace, $data['project_id'] ?? null, 'project_id');
        $task = $this->find(Task::class, $workspace, $data['task_id'] ?? null, 'task_id');

        // Core kinds (image, audio...) belong to the module that made them, if there's a task to tell.
        $module = $owner === ModuleRegistry::CORE ? ($task?->module ?? ModuleRegistry::CORE) : $owner;

        return DB::transaction(function () use ($workspace, $user, $data, $module, $project, $task) {
            $asset = Asset::create([
                'workspace_id' => $workspace->id,
                'kind' => $data['kind'],
                'module' => $module,
                'name' => $data['name'],
                'origin' => $data['origin'] ?? AssetOrigin::Created->value,
                'tags' => $data['tags'] ?? [],
                'licence' => $data['licence'] ?? null,
                'attribution' => $data['attribution'] ?? null,
            ]);

            $this->versions->write($asset, null, $data['spec'], null, $data['files'] ?? [], 'Created', $user);

            if ($project) {
                $this->changes->upsert(ProjectAsset::create(['workspace_id' => $workspace->id, 'project_id' => $project->id, 'asset_id' => $asset->id]));
            }

            if ($task) {
                $output = TaskOutput::create([
                    'workspace_id' => $workspace->id,
                    'task_id' => $task->id,
                    'asset_id' => $asset->id,
                    // The first output of a task is its primary one.
                    'role' => $task->outputs()->exists() ? TaskOutputRole::Secondary : TaskOutputRole::Primary,
                ]);
                $this->changes->upsert($output);
            }

            return $asset->refresh();
        });
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<T>  $model
     * @return T|null
     */
    private function find(string $model, Workspace $workspace, ?string $id, string $field): mixed
    {
        if ($id === null) {
            return null;
        }

        return $model::where('workspace_id', $workspace->id)->find($id)
            ?? throw ValidationException::withMessages([$field => 'Not found in this workspace.']);
    }
}
