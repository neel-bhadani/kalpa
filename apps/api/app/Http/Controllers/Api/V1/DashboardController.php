<?php

namespace App\Http\Controllers\Api\V1;

use App\Core\Workspaces\CurrentWorkspace;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DashboardController extends Controller
{
    /**
     * ?module=story&status=draft,paused&project=<id>&cursor=...
     *
     * - continue: the 3 most recently updated open tasks (not filtered)
     * - counts: tasks per status, with the module and project filters applied
     * - tasks: the filtered, cursor-paged list; archived tasks only when asked for
     */
    public function __invoke(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $request->validate([
            'module' => ['sometimes', 'string', 'max:64'],
            'project' => ['sometimes', 'uuid'],
            'status' => ['sometimes', 'string'],
        ]);

        $statuses = $this->statuses($request);

        $continue = $this->workspaceTasks($current)
            ->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Archived])
            ->with('project')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(3)
            ->get();

        $counts = $this->workspaceTasks($current)
            ->when($request->query('module'), fn ($query, $module) => $query->where('module', $module))
            ->when($request->query('project'), fn ($query, $id) => $query->where('project_id', $id))
            ->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $tasks = $this->workspaceTasks($current)
            ->when($request->query('module'), fn ($query, $module) => $query->where('module', $module))
            ->when($request->query('project'), fn ($query, $id) => $query->where('project_id', $id))
            ->when(
                $statuses,
                fn ($query) => $query->whereIn('status', $statuses),
                fn ($query) => $query->where('status', '!=', TaskStatus::Archived),
            )
            ->with('project')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->cursorPaginate($this->perPage($request));

        return response()->json([
            'data' => [
                'continue' => TaskResource::collection($continue),
                'counts' => collect(TaskStatus::cases())
                    ->mapWithKeys(fn (TaskStatus $status) => [$status->value => (int) ($counts[$status->value] ?? 0)]),
                'tasks' => TaskResource::collection($tasks->items()),
            ],
            'meta' => [
                'per_page' => $tasks->perPage(),
                'next_cursor' => $tasks->nextCursor()?->encode(),
                'prev_cursor' => $tasks->previousCursor()?->encode(),
            ],
        ]);
    }

    /**
     * Live tasks of live projects in the current workspace.
     */
    private function workspaceTasks(CurrentWorkspace $current): Builder
    {
        return Task::where('workspace_id', $current->id())->whereHas('project');
    }

    /**
     * The comma-separated ?status= list, checked against TaskStatus.
     *
     * @return list<string>
     */
    private function statuses(Request $request): array
    {
        $statuses = array_values(array_filter(explode(',', (string) $request->query('status'))));

        foreach ($statuses as $status) {
            if (TaskStatus::tryFrom($status) === null) {
                throw ValidationException::withMessages(['status' => "Unknown status \"{$status}\"."]);
            }
        }

        return $statuses;
    }
}
