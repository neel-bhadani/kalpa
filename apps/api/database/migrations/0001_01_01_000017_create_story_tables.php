<?php

use App\Core\Database\EnumCheck;
use App\Modules\Story\ScriptLineType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A read-only copy of a script asset's current version, one row per scene and per line, so
        // other tables (voice takes, later) can point at a line. The spec stays the source of truth:
        // App\Modules\Story\ScriptProjector rewrites these rows after every new version. spec_id is
        // the scene's or line's stable id in the spec; a row keeps its own id while spec_id stays.
        Schema::create('script_scenes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->index()->constrained();
            $table->foreignUuid('asset_id')->constrained();
            // The version these rows were last written from.
            $table->foreignUuid('asset_version_id')->index()->constrained();
            $table->uuid('spec_id');
            $table->integer('position');
            $table->text('heading');
            $table->text('location')->nullable();
            $table->text('time_of_day')->nullable();
            $table->text('summary')->nullable();
            $table->timestamps();

            // Also covers the asset_id foreign key.
            $table->unique(['asset_id', 'spec_id']);
        });

        Schema::create('script_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->index()->constrained();
            $table->foreignUuid('asset_id')->constrained();
            $table->foreignUuid('asset_version_id')->index()->constrained();
            $table->foreignUuid('scene_id')->index()->constrained('script_scenes')->cascadeOnDelete();
            $table->uuid('spec_id');
            $table->integer('position');
            $table->text('type');
            // A character id in the linked story's spec (not a table row).
            $table->uuid('character_spec_id')->nullable();
            $table->text('text');
            $table->text('emotion')->nullable();
            $table->timestamps();

            $table->unique(['asset_id', 'spec_id']);
        });

        EnumCheck::add('script_lines', 'type', ScriptLineType::class);

        // How two characters of a project's story relate (a_id, b_id: character ids in the story spec).
        Schema::create('character_relationships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->index()->constrained();
            $table->foreignUuid('project_id')->index()->constrained();
            $table->uuid('a_id');
            $table->uuid('b_id');
            $table->text('relation');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement('ALTER TABLE character_relationships ADD CONSTRAINT character_relationships_two_characters_check CHECK (a_id <> b_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('character_relationships');
        Schema::dropIfExists('script_lines');
        Schema::dropIfExists('script_scenes');
    }
};
