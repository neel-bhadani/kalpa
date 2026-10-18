<?php

namespace App\Modules\Story;

use App\Actions\CreateAsset;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ProjectsAssetVersions;
use App\Core\Modules\StartsTasks;
use App\Core\Sync\ChangeRecorder;
use App\Enums\TaskOutputRole;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\Task;
use App\Models\TaskOutput;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

class Manifest implements ModuleManifest, ProjectsAssetVersions, StartsTasks
{
    public function key(): string
    {
        return 'story';
    }

    public function name(): string
    {
        return 'Story & Script';
    }

    public function icon(): string
    {
        return 'book-open';
    }

    public function version(): string
    {
        return '0.2.0';
    }

    public function taskKinds(): array
    {
        return [
            'write_story' => 'Write story',
            'write_script' => 'Write script',
        ];
    }

    public function assetKinds(): array
    {
        return [
            'story' => 'Story',
            'script' => 'Script',
        ];
    }

    public function jobTypes(): array
    {
        return [];
    }

    /**
     * Scripts keep script_scenes and script_lines in step with their current version.
     */
    public function versionWritten(Asset $asset, AssetVersion $version): void
    {
        if ($asset->kind === 'script') {
            app(ScriptProjector::class)->project($asset, $version);
        }
    }

    /**
     * A new task gets the asset it works on, as its primary output: a story, or a script with one
     * empty scene. A script is linked to the project's latest story, or to a new one, which is the
     * task's secondary output, so the editor's Story panel has characters to work with.
     */
    public function taskCreated(Task $task, User $user): void
    {
        if (! in_array($task->kind, ['write_story', 'write_script'], true)) {
            return;
        }

        $create = app(CreateAsset::class);
        $workspace = Workspace::findOrFail($task->workspace_id);
        $title = Str::limit($task->title, 197);
        $make = fn (string $kind, object $spec, bool $output) => $create->handle($workspace, $user, [
            'kind' => $kind, 'name' => $title, 'spec' => $spec, 'project_id' => $task->project_id, 'task_id' => $output ? $task->id : null,
        ]);

        if ($task->kind === 'write_story') {
            $make('story', $this->newStory($title), true);

            return;
        }

        $story = Asset::where('workspace_id', $task->workspace_id)
            ->where('kind', 'story')
            ->whereHas('projectAssets', fn ($query) => $query->where('project_id', $task->project_id))
            ->latest()
            ->first()
            ?? $make('story', $this->newStory($title), false);

        $make('script', (object) [
            'title' => $title,
            'format' => 'screenplay',
            'story_asset_id' => $story->id,
            'language' => 'en',
            'scenes' => [(object) ['id' => (string) Str::uuid7(), 'heading' => '', 'lines' => []]],
        ], true);

        // The story is the task's second output (the editor opens both).
        $this->changes()->upsert(TaskOutput::create([
            'workspace_id' => $task->workspace_id, 'task_id' => $task->id, 'asset_id' => $story->id, 'role' => TaskOutputRole::Secondary,
        ]));
    }

    private function changes(): ChangeRecorder
    {
        return app(ChangeRecorder::class);
    }

    private function newStory(string $title): object
    {
        return (object) ['title' => $title, 'language' => 'en', 'acts' => [], 'characters' => [], 'themes' => []];
    }
}
