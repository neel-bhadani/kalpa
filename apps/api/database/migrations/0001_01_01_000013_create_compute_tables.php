<?php

use App\Core\Database\AppendOnly;
use App\Core\Database\EnumCheck;
use App\Enums\JobLogLevel;
use App\Enums\JobPriority;
use App\Enums\JobStatus;
use App\Enums\JobTarget;
use App\Enums\WorkerKind;
use App\Enums\WorkerStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Something that can run jobs for a user in a workspace: a browser tab, a local worker, the desktop app.
        Schema::create('compute_workers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->index()->constrained();
            $table->foreignUuid('user_id')->index()->constrained();
            $table->foreignUuid('user_device_id')->nullable()->index()->constrained();
            $table->text('kind');
            $table->string('name');
            $table->jsonb('capabilities')->default('[]');
            // SHA-256 of the worker's token (paired workers only; browser tabs use the user's session).
            $table->char('pairing_token_hash', 64)->nullable()->unique();
            $table->text('status')->default(WorkerStatus::Offline->value);
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        EnumCheck::add('compute_workers', 'kind', WorkerKind::class);
        EnumCheck::add('compute_workers', 'status', WorkerStatus::class);
        // One browser worker per device and workspace.
        DB::statement("CREATE UNIQUE INDEX compute_workers_browser_device_unique ON compute_workers (workspace_id, user_device_id) WHERE kind = 'browser' AND deleted_at IS NULL");

        // Heavy or AI work, run on the user's device, one of their workers, or the server queue.
        Schema::create('jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained();
            $table->foreignUuid('task_id')->nullable()->index()->constrained();
            // Foreign key to ai_steps comes in P0 step 9.
            $table->uuid('ai_step_id')->nullable()->index();
            // Who queued it: device and worker jobs only run on this user's own devices and workers.
            $table->foreignUuid('created_by')->nullable()->index()->constrained('users');
            $table->text('type');
            $table->text('target');
            $table->text('status')->default(JobStatus::Queued->value);
            $table->text('priority')->default(JobPriority::Normal->value);
            $table->foreignUuid('worker_id')->nullable()->index()->constrained('compute_workers');
            $table->text('model_key')->nullable();
            $table->jsonb('input')->default('{}');
            $table->jsonb('output')->nullable();
            $table->text('idempotency_key');
            $table->smallInteger('progress')->default(0);
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts')->default(3);
            $table->text('error')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->integer('cost_seconds')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // Also covers the workspace_id foreign key.
            $table->unique(['workspace_id', 'idempotency_key']);
            // Claiming, and the expired-lease sweep.
            $table->index(['status', 'target', 'priority', 'queued_at']);
            $table->index(['status', 'lease_until']);
        });

        DB::statement('ALTER TABLE jobs ADD CONSTRAINT jobs_progress_check CHECK (progress BETWEEN 0 AND 100)');
        EnumCheck::add('jobs', 'target', JobTarget::class);
        EnumCheck::add('jobs', 'status', JobStatus::class);
        EnumCheck::add('jobs', 'priority', JobPriority::class);

        Schema::create('job_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->index()->constrained();
            $table->foreignUuid('job_id')->constrained();
            $table->text('level')->default(JobLogLevel::Info->value);
            $table->text('message');
            $table->jsonb('data')->default('{}');
            $table->timestamp('created_at')->useCurrent();

            // Also covers the job_id foreign key.
            $table->index(['job_id', 'created_at']);
        });

        EnumCheck::add('job_logs', 'level', JobLogLevel::class);
        AppendOnly::apply('job_logs');

        // Laravel's standard notifications table, with uuid ids for the notifiable (users).
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->uuidMorphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('job_logs');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('compute_workers');
    }
};
