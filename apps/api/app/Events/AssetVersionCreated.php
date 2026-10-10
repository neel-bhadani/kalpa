<?php

namespace App\Events;

use App\Models\AssetVersion;
use App\Models\ProjectAsset;
use App\Models\TaskOutput;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A new asset version. Sent to the projects that use the asset and the tasks that produced it.
 */
class AssetVersionCreated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    // SerializesModels: the queued broadcast re-reads the row, so events that arrive
    // out of order still carry the latest state.
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public AssetVersion $version) {}

    public function broadcastOn(): array
    {
        $assetId = $this->version->asset_id;

        $projects = ProjectAsset::where('asset_id', $assetId)->pluck('project_id')
            ->map(fn (string $id) => new PrivateChannel("project.{$id}"));
        $tasks = TaskOutput::where('asset_id', $assetId)->pluck('task_id')
            ->map(fn (string $id) => new PrivateChannel("task.{$id}"));

        return [...$projects, ...$tasks];
    }

    public function broadcastAs(): string
    {
        return 'AssetVersionCreated';
    }

    public function broadcastWith(): array
    {
        return [
            'asset_id' => $this->version->asset_id,
            'version_id' => $this->version->id,
            'number' => $this->version->number,
            'message' => $this->version->message,
        ];
    }
}
