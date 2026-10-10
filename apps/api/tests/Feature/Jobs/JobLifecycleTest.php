<?php

use App\Actions\ClaimJob;
use App\Enums\JobPriority;
use App\Enums\JobStatus;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Models\ComputeWorker;
use App\Models\Job;
use App\Models\Task;
use App\Notifications\JobFailed;
use App\Notifications\JobFinished;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

it('creates a job once per idempotency key', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $body = ['type' => 'demo.count', 'target' => 'device', 'input' => ['n' => 5], 'idempotency_key' => 'click-1'];

    $first = actingIn($ada, $workspace)->postJson('/api/v1/jobs', $body)->assertCreated()->json('data.id');
    actingIn($ada, $workspace)->postJson('/api/v1/jobs', $body)->assertOk()->assertJsonPath('data.id', $first);

    expect(Job::count())->toBe(1);

    actingIn($ada, $workspace)->postJson('/api/v1/jobs', ['type' => 'demo.nope'])->assertUnprocessable()->assertJsonValidationErrors('type');
    actingIn($ada, $workspace)->postJson('/api/v1/jobs', ['type' => 'demo.count', 'target' => 'server'])->assertUnprocessable()->assertJsonValidationErrors('target');
});

it('moves the task to waiting_job when a job is queued for it', function () {
    $ada = owner();
    $task = makeTask(makeProject($ada), $ada);

    actingIn($ada, workspaceOf($ada))->postJson('/api/v1/jobs', ['type' => 'demo.count', 'target' => 'device', 'task_id' => $task->id])
        ->assertCreated();

    // draft can't go straight to waiting_job, so it goes through in_progress.
    expect($task->refresh()->status)->toBe(TaskStatus::WaitingJob)
        ->and($task->events()->where('type', 'status_changed')->pluck('to_status')->map->value->all())->toBe(['in_progress', 'waiting_job']);
});

it('claims the highest priority first, then the oldest', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    [$worker, $token] = pairedWorker($ada, $workspace);

    $oldBackground = demoJob($ada, $workspace, priority: JobPriority::Background);
    $this->travel(1)->minutes();
    $oldNormal = demoJob($ada, $workspace);
    $this->travel(1)->minutes();
    $newNormal = demoJob($ada, $workspace);
    $this->travel(1)->minutes();
    $interactive = demoJob($ada, $workspace, priority: JobPriority::Interactive);

    $claimed = collect(range(1, 5))->map(fn () => asWorker($token)->postJson('/api/v1/workers/claim')->assertOk()->json('data.id'));

    expect($claimed->all())->toBe([$interactive->id, $oldNormal->id, $newNormal->id, $oldBackground->id, null]);
    expect($interactive->refresh())
        ->status->toBe(JobStatus::Assigned)
        ->worker_id->toBe($worker->id)
        ->and($interactive->lease_until->isFuture())->toBeTrue();
});

it('never gives two parallel claims the same job', function () {
    // The second connection only sees committed rows, so this test commits its own data there
    // (outside the test transaction) and removes it again at the end.
    config(['database.connections.second' => config('database.connections.pgsql')]);
    $second = DB::connection('second');
    $ids = ['user' => (string) Str::uuid7(), 'workspace' => (string) Str::uuid7(), 'worker' => (string) Str::uuid7(),
        'job1' => (string) Str::uuid7(), 'job2' => (string) Str::uuid7()];
    $now = now();

    $second->table('users')->insert(['id' => $ids['user'], 'name' => 'Racer', 'email' => "racer-{$ids['user']}@example.com", 'password' => 'x', 'created_at' => $now, 'updated_at' => $now]);
    $second->table('workspaces')->insert(['id' => $ids['workspace'], 'name' => 'Race', 'owner_id' => $ids['user'], 'created_at' => $now, 'updated_at' => $now]);
    $second->table('workspace_members')->insert(['id' => (string) Str::uuid7(), 'workspace_id' => $ids['workspace'], 'user_id' => $ids['user'], 'role' => 'owner', 'created_at' => $now, 'updated_at' => $now]);
    $second->table('compute_workers')->insert(['id' => $ids['worker'], 'workspace_id' => $ids['workspace'], 'user_id' => $ids['user'], 'kind' => 'local', 'name' => 'W', 'status' => 'online', 'created_at' => $now, 'updated_at' => $now]);
    foreach (['job1', 'job2'] as $i => $job) {
        $second->table('jobs')->insert(['id' => $ids[$job], 'workspace_id' => $ids['workspace'], 'created_by' => $ids['user'], 'type' => 'demo.count',
            'target' => 'worker', 'status' => 'queued', 'priority' => 'normal', 'idempotency_key' => $job, 'queued_at' => $now->copy()->addSeconds($i),
            'created_at' => $now, 'updated_at' => $now]);
    }

    // Remove the committed rows after the test transaction has been rolled back (it holds a lock on job2).
    $this->beforeApplicationDestroyed(function () use ($second, $ids) {
        $second->table('jobs')->whereIn('id', [$ids['job1'], $ids['job2']])->delete();
        $second->table('compute_workers')->where('id', $ids['worker'])->delete();
        $second->table('workspace_members')->where('workspace_id', $ids['workspace'])->delete();
        $second->table('workspaces')->where('id', $ids['workspace'])->delete();
        $second->table('users')->where('id', $ids['user'])->delete();
    });

    $second->beginTransaction();
    try {
        // Another worker is in the middle of claiming job1 (the oldest).
        $second->selectOne('SELECT id FROM jobs WHERE id = ? FOR UPDATE', [$ids['job1']]);

        $claimed = app(ClaimJob::class)->handle(ComputeWorker::find($ids['worker']), []);

        // This claim skipped the locked row instead of waiting for it or taking it too.
        expect($claimed->id)->toBe($ids['job2']);

        // And the other worker can't see job2 now that it's taken.
        $seen = $second->select("SELECT id FROM jobs WHERE workspace_id = ? AND status = 'queued' ORDER BY queued_at FOR UPDATE SKIP LOCKED", [$ids['workspace']]);
        expect(array_column($seen, 'id'))->not->toContain($ids['job2']);
    } finally {
        $second->rollBack();
    }
});

it('requeues a job whose lease expired, then fails it after max_attempts', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $task = makeTask(makeProject($ada), $ada);
    [, $token] = pairedWorker($ada, $workspace);
    $job = demoJob($ada, $workspace, $task);

    foreach ([1, 2] as $attempt) {
        asWorker($token)->postJson('/api/v1/workers/claim')->assertJsonPath('data.id', $job->id);
        $this->travel(61)->seconds();
        $this->artisan('jobs:requeue-expired')->expectsOutput('Requeued 1 job(s), failed 0.');

        expect($job->refresh())->status->toBe(JobStatus::Queued)->attempts->toBe($attempt)->worker_id->toBeNull();
    }

    asWorker($token)->postJson('/api/v1/workers/claim')->assertJsonPath('data.id', $job->id);
    $this->travel(61)->seconds();
    $this->artisan('jobs:requeue-expired')->expectsOutput('Requeued 0 job(s), failed 1.');

    expect($job->refresh())->status->toBe(JobStatus::Failed)->attempts->toBe(3)
        ->and($task->refresh()->status)->toBe(TaskStatus::Failed);
});

it('runs a job through heartbeat and complete, moving the task to needs_review', function () {
    Notification::fake();
    $ada = owner();
    $workspace = workspaceOf($ada);
    $task = makeTask(makeProject($ada), $ada);
    [$worker, $token] = pairedWorker($ada, $workspace);
    $job = demoJob($ada, $workspace, $task);

    asWorker($token)->postJson('/api/v1/workers/claim')->assertJsonPath('data.status', 'assigned');
    $this->travel(30)->seconds();

    asWorker($token)->postJson("/api/v1/jobs/{$job->id}/heartbeat", ['progress' => 40, 'log' => ['message' => 'Counted to 2']])
        ->assertOk()
        ->assertJsonPath('data.status', 'running');

    expect($job->refresh())->status->toBe(JobStatus::Running)->progress->toBe(40)
        ->and(now()->diffInSeconds($job->lease_until))->toBeGreaterThan(55)
        ->and($task->refresh()->progress)->toBe(40)
        ->and($job->logs()->pluck('message')->all())->toContain('Counted to 2');

    asWorker($token)->postJson("/api/v1/jobs/{$job->id}/complete", ['output' => ['counted' => 3]])
        ->assertOk()
        ->assertJsonPath('data.status', 'succeeded')
        ->assertJsonPath('data.output.counted', 3);

    // Events in the same second have no fixed order, so look the move up by its target status.
    $last = $task->events()->where('to_status', TaskStatus::NeedsReview)->sole();
    expect($task->refresh()->status)->toBe(TaskStatus::NeedsReview)
        ->and($task->progress)->toBe(100)
        ->and($last->actor_type->value)->toBe('system')
        ->and($last->data->job_id)->toBe($job->id);
    Notification::assertSentTo($ada, JobFinished::class);

    // Completing again is refused: the job is no longer held.
    asWorker($token)->postJson("/api/v1/jobs/{$job->id}/complete")->assertConflict();
});

it('returns an auto-mode task to in_progress when its job succeeds', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $task = makeTask(makeProject($ada), $ada);
    $task->forceFill(['mode' => TaskMode::Auto])->save();
    [, $token] = pairedWorker($ada, $workspace);
    $job = demoJob($ada, $workspace, $task);

    asWorker($token)->postJson('/api/v1/workers/claim');
    asWorker($token)->postJson("/api/v1/jobs/{$job->id}/complete")->assertOk();

    expect($task->refresh()->status)->toBe(TaskStatus::InProgress);
});

it('fails the task with the error when the job fails', function () {
    Notification::fake();
    $ada = owner();
    $workspace = workspaceOf($ada);
    $task = makeTask(makeProject($ada), $ada);
    [, $token] = pairedWorker($ada, $workspace);
    $job = demoJob($ada, $workspace, $task);

    asWorker($token)->postJson('/api/v1/workers/claim');
    asWorker($token)->postJson("/api/v1/jobs/{$job->id}/fail", ['error' => 'Out of memory'])
        ->assertOk()
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.error', 'Out of memory');

    $last = $task->events()->where('to_status', TaskStatus::Failed)->sole();
    expect($task->refresh()->status)->toBe(TaskStatus::Failed)
        ->and($last->data->error)->toBe('Out of memory');
    Notification::assertSentTo($ada, JobFailed::class);
});

it('tells the worker to stop when the job is paused or cancelled', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $task = makeTask(makeProject($ada), $ada);
    [, $token] = pairedWorker($ada, $workspace);
    $job = demoJob($ada, $workspace, $task);

    asWorker($token)->postJson('/api/v1/workers/claim');
    actingIn($ada, $workspace)->postJson("/api/v1/jobs/{$job->id}/pause")->assertOk()->assertJsonPath('data.status', 'paused');
    asWorker($token)->postJson("/api/v1/jobs/{$job->id}/heartbeat", ['progress' => 10])->assertJsonPath('data.status', 'lost');

    actingIn($ada, $workspace)->postJson("/api/v1/jobs/{$job->id}/resume")->assertJsonPath('data.status', 'queued');
    asWorker($token)->postJson('/api/v1/workers/claim')->assertJsonPath('data.id', $job->id);
    actingIn($ada, $workspace)->postJson("/api/v1/jobs/{$job->id}/cancel")->assertJsonPath('data.status', 'cancelled');
    asWorker($token)->postJson("/api/v1/jobs/{$job->id}/heartbeat")->assertJsonPath('data.status', 'cancelled');

    // A cancelled job hands the task back.
    expect($task->refresh()->status)->toBe(TaskStatus::InProgress);

    actingIn($ada, $workspace)->postJson("/api/v1/jobs/{$job->id}/retry")->assertJsonPath('data.status', 'queued');
    expect($task->refresh()->status)->toBe(TaskStatus::WaitingJob);
});

it("keeps workers out of other workspaces' jobs", function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $bob = owner('Bob');
    [, $adaToken] = pairedWorker($ada, $workspace);
    [, $bobToken] = pairedWorker($bob, workspaceOf($bob));
    $job = demoJob($ada, $workspace);

    asWorker($bobToken)->postJson('/api/v1/workers/claim')->assertOk()->assertJsonPath('data', null);

    asWorker($adaToken)->postJson('/api/v1/workers/claim')->assertJsonPath('data.id', $job->id);
    asWorker($bobToken)->postJson("/api/v1/jobs/{$job->id}/complete")->assertForbidden();
    asWorker($bobToken)->postJson("/api/v1/jobs/{$job->id}/heartbeat")->assertForbidden();
    asWorker($bobToken)->postJson("/api/v1/jobs/{$job->id}/fail", ['error' => 'x'])->assertForbidden();

    // A bad token gets nowhere.
    asWorker('kw_nonsense')->postJson('/api/v1/workers/claim')->assertUnauthorized();
    expect($job->refresh()->status)->toBe(JobStatus::Assigned);
});

it('only lets the owner of a device job run it', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $teammate = memberOf($workspace, WorkspaceRole::Editor);
    [, $teammateToken] = pairedWorker($teammate, $workspace);
    demoJob($ada, $workspace);

    asWorker($teammateToken)->postJson('/api/v1/workers/claim')->assertJsonPath('data', null);
});

it('lists my jobs and a task\'s jobs, with logs on one job', function () {
    $ada = owner();
    $workspace = workspaceOf($ada);
    $task = makeTask(makeProject($ada), $ada);
    $mine = demoJob($ada, $workspace, $task);
    demoJob(memberOf($workspace, WorkspaceRole::Editor), $workspace);

    actingIn($ada, $workspace)->getJson('/api/v1/jobs')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
    actingIn($ada, $workspace)->getJson("/api/v1/jobs?task={$task->id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.task_title', $task->title);
    actingIn($ada, $workspace)->getJson("/api/v1/jobs/{$mine->id}")->assertJsonPath('data.logs.0.message', 'Queued for worker');
});
