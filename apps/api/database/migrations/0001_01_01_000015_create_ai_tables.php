<?php

use App\Core\Database\AppendOnly;
use App\Core\Database\EnumCheck;
use App\Enums\AiAssistant;
use App\Enums\AiHandoffStatus;
use App\Enums\AiProviderKind;
use App\Enums\AiRole;
use App\Enums\AiSessionStatus;
use App\Enums\AiStepStatus;
use App\Enums\ComputeTarget;
use App\Enums\DeviceTier;
use App\Enums\ModelCapability;
use App\Enums\TaskMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every model Kalpa knows about (global, seeded from config/models.php).
        Schema::create('model_registry', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('key')->unique();
            $table->text('capability');
            $table->text('engine');
            $table->string('display_name');
            $table->text('licence');
            $table->boolean('commercial_ok');
            $table->integer('size_mb');
            $table->text('min_tier');
            $table->text('source_url');
            $table->char('sha256', 64)->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE model_registry ADD COLUMN targets text[] NOT NULL DEFAULT '{}'");
        // A model may only be switched on if its licence allows commercial use.
        DB::statement('ALTER TABLE model_registry ADD CONSTRAINT model_registry_enabled_commercial_check CHECK (NOT enabled OR commercial_ok)');
        EnumCheck::add('model_registry', 'capability', ModelCapability::class);
        EnumCheck::add('model_registry', 'min_tier', DeviceTier::class);

        // A user's own API endpoints (OpenAI-compatible or Ollama). The key is stored encrypted.
        Schema::create('ai_provider_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->index()->constrained();
            $table->text('provider');
            $table->string('name');
            $table->text('base_url');
            // Encrypted with the app key (Laravel "encrypted" cast); never sent back by the API.
            $table->text('api_key')->nullable();
            $table->jsonb('models')->default('[]');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        EnumCheck::add('ai_provider_configs', 'provider', AiProviderKind::class);

        // One conversation with an assistant, usually about a task. The Producer's planning
        // sessions belong to a project instead (no task).
        Schema::create('ai_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->index()->constrained();
            $table->foreignUuid('project_id')->index()->constrained();
            $table->foreignUuid('task_id')->nullable()->index()->constrained();
            $table->text('module');
            $table->text('assistant');
            $table->text('mode');
            $table->text('status')->default(AiSessionStatus::Active->value);
            $table->text('brain_model_key');
            $table->text('compute_target');
            $table->jsonb('plan')->default('{}');
            $table->text('summary')->nullable();
            $table->foreignUuid('created_by')->nullable()->index()->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });

        EnumCheck::add('ai_sessions', 'assistant', AiAssistant::class);
        EnumCheck::add('ai_sessions', 'mode', TaskMode::class);
        EnumCheck::add('ai_sessions', 'status', AiSessionStatus::class);
        EnumCheck::add('ai_sessions', 'compute_target', ComputeTarget::class);

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->index()->constrained();
            $table->foreignUuid('session_id')->constrained('ai_sessions');
            $table->text('role');
            $table->jsonb('content');
            $table->integer('tokens')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Also covers the session_id foreign key.
            $table->index(['session_id', 'created_at']);
        });

        EnumCheck::add('ai_messages', 'role', AiRole::class);
        AppendOnly::apply('ai_messages');

        // One thing an assistant does (a module command), visible, reviewable and undoable.
        Schema::create('ai_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->index()->constrained();
            $table->foreignUuid('session_id')->constrained('ai_sessions');
            $table->integer('seq');
            $table->string('title');
            $table->text('tool');
            $table->jsonb('args')->default('{}');
            $table->text('status')->default(AiStepStatus::Planned->value);
            $table->foreignUuid('asset_id')->nullable()->index()->constrained();
            // What the step touched, before and after (for undo).
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->foreignUuid('before_version_id')->nullable()->index()->constrained('asset_versions');
            $table->foreignUuid('after_version_id')->nullable()->index()->constrained('asset_versions');
            $table->foreignUuid('job_id')->nullable()->index()->constrained();
            $table->foreignUuid('preview_file_id')->nullable()->index()->constrained('files');
            $table->text('error')->nullable();
            $table->timestamps();

            // Also covers the session_id foreign key.
            $table->unique(['session_id', 'seq']);
        });

        EnumCheck::add('ai_steps', 'status', AiStepStatus::class);

        // Work one assistant passes to another (e.g. the Producer to the Story assistant).
        Schema::create('ai_handoffs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->index()->constrained();
            $table->foreignUuid('from_session_id')->index()->constrained('ai_sessions');
            $table->foreignUuid('to_session_id')->nullable()->index()->constrained('ai_sessions');
            $table->foreignUuid('task_id')->index()->constrained();
            $table->jsonb('request');
            $table->jsonb('result')->nullable();
            $table->text('status')->default(AiHandoffStatus::Pending->value);
            $table->timestamps();
        });

        EnumCheck::add('ai_handoffs', 'status', AiHandoffStatus::class);

        // The ai_step_id columns made earlier now point at ai_steps (they're already indexed).
        Schema::table('asset_versions', fn (Blueprint $table) => $table->foreign('ai_step_id')->references('id')->on('ai_steps'));
        Schema::table('jobs', fn (Blueprint $table) => $table->foreign('ai_step_id')->references('id')->on('ai_steps'));
    }

    public function down(): void
    {
        Schema::table('jobs', fn (Blueprint $table) => $table->dropForeign(['ai_step_id']));
        Schema::table('asset_versions', fn (Blueprint $table) => $table->dropForeign(['ai_step_id']));
        Schema::dropIfExists('ai_handoffs');
        Schema::dropIfExists('ai_steps');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_sessions');
        Schema::dropIfExists('ai_provider_configs');
        Schema::dropIfExists('model_registry');
    }
};
