<?php

use App\Core\Database\AppendOnly;
use App\Core\Database\EnumCheck;
use App\Enums\ActorType;
use App\Enums\TaskAssignee;
use App\Enums\TaskEventType;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained();
            $table->foreignUuid('project_id')->constrained();
            // Lowercase module key, e.g. "story". Checked against the module registry in step 5.
            $table->text('module');
            $table->text('kind');
            $table->string('title');
            $table->text('status')->default(TaskStatus::Draft->value);
            $table->text('mode')->default(TaskMode::Manual->value);
            $table->text('assigned_to')->default(TaskAssignee::User->value);
            $table->uuid('parent_task_id')->nullable()->index();
            $table->jsonb('checkpoint')->default('{}');
            $table->boolean('has_unsynced_changes')->default(false);
            $table->smallInteger('progress')->default(0);
            $table->integer('row_version')->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignUuid('created_by')->index()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            // Also cover the project_id foreign key.
            $table->index(['project_id', 'status']);
        });

        // Added after the table exists, since it points at tasks itself.
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreign('parent_task_id')->references('id')->on('tasks');
        });

        // Also covers the workspace_id foreign key.
        DB::statement('CREATE INDEX tasks_workspace_id_updated_at_index ON tasks (workspace_id, updated_at DESC)');
        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_progress_check CHECK (progress BETWEEN 0 AND 100)');
        EnumCheck::add('tasks', 'status', TaskStatus::class);
        EnumCheck::add('tasks', 'mode', TaskMode::class);
        EnumCheck::add('tasks', 'assigned_to', TaskAssignee::class);

        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->index()->constrained();
            $table->foreignUuid('task_id')->constrained('tasks');
            $table->foreignUuid('depends_on_task_id')->index()->constrained('tasks');
            $table->timestamps();

            // Also covers the task_id foreign key.
            $table->unique(['task_id', 'depends_on_task_id']);
        });

        DB::statement('ALTER TABLE task_dependencies ADD CONSTRAINT task_dependencies_not_self_check CHECK (task_id <> depends_on_task_id)');

        Schema::create('task_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->index()->constrained();
            $table->foreignUuid('task_id')->constrained('tasks');
            $table->text('type');
            $table->text('from_status')->nullable();
            $table->text('to_status')->nullable();
            $table->text('actor_type');
            // A user id for people; assistants and the system get ids in later steps.
            $table->uuid('actor_id')->nullable();
            $table->jsonb('data')->default('{}');
            $table->timestamp('created_at')->useCurrent();

            // Also covers the task_id foreign key.
            $table->index(['task_id', 'created_at']);
        });

        EnumCheck::add('task_events', 'type', TaskEventType::class);
        EnumCheck::add('task_events', 'from_status', TaskStatus::class);
        EnumCheck::add('task_events', 'to_status', TaskStatus::class);
        EnumCheck::add('task_events', 'actor_type', ActorType::class);
        AppendOnly::apply('task_events');
    }

    public function down(): void
    {
        Schema::dropIfExists('task_events');
        Schema::dropIfExists('task_dependencies');
        Schema::dropIfExists('tasks');
    }
};
