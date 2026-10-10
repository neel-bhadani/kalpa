<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * "There are new changes, pull now": sent on project channels after change_log rows commit.
 */
class SyncNudge implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  list<string>  $projectIds
     */
    public function __construct(public array $projectIds, public int $seq) {}

    public function broadcastOn(): array
    {
        return array_map(fn (string $id) => new PrivateChannel("project.{$id}"), array_values(array_unique($this->projectIds)));
    }

    public function broadcastAs(): string
    {
        return 'sync.nudge';
    }

    public function broadcastWith(): array
    {
        return ['seq' => $this->seq];
    }
}
