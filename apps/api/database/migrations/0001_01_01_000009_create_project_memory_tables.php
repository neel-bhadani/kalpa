<?php

use App\Core\Database\AppendOnly;
use App\Core\Database\EnumCheck;
use App\Enums\ActorType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_memory', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->index()->constrained();
            $table->foreignUuid('project_id')->unique()->constrained();
            $table->jsonb('data')->default('{"story_bible":{},"style":{},"decisions":[]}');
            $table->integer('row_version')->default(1);
            $table->timestamps();
        });

        Schema::create('project_memory_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->index()->constrained();
            $table->foreignUuid('project_memory_id')->constrained('project_memory');
            // Matches project_memory.row_version after this change.
            $table->integer('number');
            // RFC 6902 operations that turn version number - 1 into this one.
            $table->jsonb('patch');
            $table->text('created_by_type');
            $table->uuid('created_by_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Also covers the project_memory_id foreign key.
            $table->unique(['project_memory_id', 'number']);
        });

        EnumCheck::add('project_memory_versions', 'created_by_type', ActorType::class);
        AppendOnly::apply('project_memory_versions');
    }

    public function down(): void
    {
        Schema::dropIfExists('project_memory_versions');
        Schema::dropIfExists('project_memory');
    }
};
