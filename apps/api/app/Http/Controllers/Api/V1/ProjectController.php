<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateProject;
use App\Actions\DeleteProject;
use App\Actions\OpenProject;
use App\Actions\UpdateProject;
use App\Core\Workspaces\CurrentWorkspace;
use App\Enums\AspectRatio;
use App\Enums\ProjectStatus;
use App\Enums\ProjectStyle;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function index(Request $request, CurrentWorkspace $current): AnonymousResourceCollection
    {
        $request->validate(['status' => ['sometimes', Rule::enum(ProjectStatus::class)]]);

        $projects = Project::where('workspace_id', $current->id())
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($this->perPage($request));

        return ProjectResource::collection($projects);
    }

    public function store(Request $request, CurrentWorkspace $current, CreateProject $createProject): ProjectResource
    {
        Gate::authorize('create', Project::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'style' => ['sometimes', Rule::enum(ProjectStyle::class)],
            'aspect_ratio' => ['sometimes', Rule::enum(AspectRatio::class)],
        ]);

        return new ProjectResource($createProject->handle($current->get(), $request->user(), $data));
    }

    public function show(Project $project): ProjectResource
    {
        Gate::authorize('view', $project);

        return new ProjectResource($project);
    }

    public function update(Request $request, Project $project, UpdateProject $updateProject): ProjectResource
    {
        Gate::authorize('update', $project);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'style' => ['sometimes', Rule::enum(ProjectStyle::class)],
            'aspect_ratio' => ['sometimes', Rule::enum(AspectRatio::class)],
            'status' => ['sometimes', Rule::enum(ProjectStatus::class)],
        ]);

        return new ProjectResource($updateProject->handle($project, $data));
    }

    public function destroy(Request $request, Project $project, DeleteProject $deleteProject): Response
    {
        Gate::authorize('delete', $project);

        $deleteProject->handle($project, $request->user());

        return response()->noContent();
    }

    public function open(Project $project, OpenProject $openProject): ProjectResource
    {
        Gate::authorize('view', $project);

        return new ProjectResource($openProject->handle($project));
    }
}
