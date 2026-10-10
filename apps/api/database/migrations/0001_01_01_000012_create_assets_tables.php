<?php

use App\Core\Database\AppendOnly;
use App\Core\Database\EnumCheck;
use App\Enums\ActorType;
use App\Enums\AssetFileRole;
use App\Enums\AssetOrigin;
use App\Enums\AssetRelation;
use App\Enums\DerivativeKind;
use App\Enums\TaskOutputRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every asset of every kind. kind is checked against the module registry in code, not here,
        // because modules add kinds.
        Schema::create('assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained();
            $table->text('kind');
            // The owning module's key, or "core" (for image, audio, video, file).
            $table->text('module');
            $table->string('name');
            // Foreign key added below, once asset_versions exists.
            $table->uuid('current_version_id')->nullable()->index();
            $table->text('origin')->default(AssetOrigin::Created->value);
            $table->text('licence')->nullable();
            $table->text('attribution')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Also covers the workspace_id foreign key.
            $table->index(['workspace_id', 'kind']);
            // Lets asset_links require both ends in the same workspace (composite foreign keys).
            $table->unique(['id', 'workspace_id']);
        });

        EnumCheck::add('assets', 'origin', AssetOrigin::class);

        // Tags, and a search vector over name + tags. array_to_string isn't marked immutable,
        // so a small immutable wrapper is needed for the generated column. 'simple' config:
        // no language-specific stemming, so names and tags in any language match as typed.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION kalpa_tags_text(text[]) RETURNS text
            LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT array_to_string($1, ' ') $$;

            ALTER TABLE assets ADD COLUMN tags text[] NOT NULL DEFAULT '{}';
            ALTER TABLE assets ADD COLUMN search tsvector GENERATED ALWAYS AS
                (to_tsvector('simple', name || ' ' || kalpa_tags_text(tags))) STORED;

            CREATE INDEX assets_tags_gin ON assets USING gin (tags);
            CREATE INDEX assets_search_gin ON assets USING gin (search);
            SQL);

        Schema::create('asset_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->index()->constrained();
            $table->foreignUuid('asset_id')->constrained();
            $table->integer('number');
            $table->uuid('parent_version_id')->nullable()->index();
            // x-schema-version of the kind's schema when this version was written.
            $table->integer('schema_version');
            $table->jsonb('spec');
            // The JSON Patch that produced this version from its parent, when it was made by a patch.
            $table->jsonb('patch')->nullable();
            $table->text('created_by_type');
            $table->uuid('created_by_id')->nullable();
            // Foreign key to ai_steps comes in P0 step 9.
            $table->uuid('ai_step_id')->nullable()->index();
            $table->text('message')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Also covers the asset_id foreign key.
            $table->unique(['asset_id', 'number']);
        });

        Schema::table('asset_versions', function (Blueprint $table) {
            $table->foreign('parent_version_id')->references('id')->on('asset_versions');
        });

        EnumCheck::add('asset_versions', 'created_by_type', ActorType::class);
        AppendOnly::apply('asset_versions');

        Schema::table('assets', function (Blueprint $table) {
            $table->foreign('current_version_id')->references('id')->on('asset_versions');
        });

        // The files of one asset version, by role. Rows are only ever inserted.
        Schema::create('asset_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->index()->constrained();
            $table->foreignUuid('asset_version_id')->constrained();
            $table->foreignUuid('file_id')->index()->constrained();
            $table->text('role');
            $table->timestamp('created_at')->useCurrent();

            // Also covers the asset_version_id foreign key.
            $table->unique(['asset_version_id', 'role', 'file_id']);
        });

        EnumCheck::add('asset_files', 'role', AssetFileRole::class);

        // Files made from other files (proxies, thumbnails, LODs). Filled by jobs in P0 step 7.
        Schema::create('file_derivatives', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->index()->constrained();
            $table->foreignUuid('source_file_id')->constrained('files');
            $table->text('kind');
            $table->jsonb('params')->default('{}');
            $table->foreignUuid('file_id')->index()->constrained('files');
            $table->timestamps();

            // Also covers the source_file_id foreign key.
            $table->unique(['source_file_id', 'kind']);
        });

        EnumCheck::add('file_derivatives', 'kind', DerivativeKind::class);

        Schema::create('asset_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->index()->constrained();
            $table->uuid('from_asset_id');
            $table->uuid('to_asset_id');
            $table->text('relation');
            $table->foreignUuid('from_version_id')->nullable()->index()->constrained('asset_versions');
            $table->jsonb('data')->default('{}');
            $table->timestamps();

            // Both ends must be assets of this link's workspace.
            $table->foreign(['from_asset_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('assets');
            $table->foreign(['to_asset_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('assets');
            // Also covers the from_asset_id foreign key.
            $table->unique(['from_asset_id', 'to_asset_id', 'relation']);
            $table->index(['to_asset_id', 'workspace_id']);
        });

        DB::statement('ALTER TABLE asset_links ADD CONSTRAINT asset_links_not_self_check CHECK (from_asset_id <> to_asset_id)');
        EnumCheck::add('asset_links', 'relation', AssetRelation::class);

        Schema::create('project_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->index()->constrained();
            $table->foreignUuid('project_id')->constrained();
            $table->foreignUuid('asset_id')->index()->constrained();
            // Null means "always the latest version".
            $table->foreignUuid('pinned_version_id')->nullable()->index()->constrained('asset_versions');
            $table->text('role')->nullable();
            $table->timestamps();

            // Also covers the project_id foreign key.
            $table->unique(['project_id', 'asset_id']);
        });

        Schema::create('task_outputs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->index()->constrained();
            $table->foreignUuid('task_id')->constrained();
            $table->foreignUuid('asset_id')->index()->constrained();
            $table->text('role')->default(TaskOutputRole::Primary->value);
            $table->timestamps();

            // Also covers the task_id foreign key.
            $table->unique(['task_id', 'asset_id']);
        });

        EnumCheck::add('task_outputs', 'role', TaskOutputRole::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('task_outputs');
        Schema::dropIfExists('project_assets');
        Schema::dropIfExists('asset_links');
        Schema::dropIfExists('file_derivatives');
        Schema::dropIfExists('asset_files');
        Schema::table('assets', fn (Blueprint $table) => $table->dropForeign(['current_version_id']));
        Schema::dropIfExists('asset_versions');
        Schema::dropIfExists('assets');
        DB::unprepared('DROP FUNCTION IF EXISTS kalpa_tags_text(text[])');
    }
};
