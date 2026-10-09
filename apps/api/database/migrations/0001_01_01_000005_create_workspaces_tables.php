<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->foreignUuid('owner_id')->index()->constrained('users');
            $table->text('plan')->default('free');
            $table->bigInteger('storage_limit_bytes')->default(5 * 1024 ** 3); // 5 GB
            $table->timestamps();
            $table->softDeletes();
        });

        // Mirrors App\Enums\WorkspacePlan.
        DB::statement("ALTER TABLE workspaces ADD CONSTRAINT workspaces_plan_check CHECK (plan IN ('free', 'pro', 'team'))");

        Schema::create('workspace_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->index()->constrained();
            $table->foreignUuid('user_id')->index()->constrained();
            $table->text('role');
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
        });

        // Mirrors App\Enums\WorkspaceRole.
        DB::statement("ALTER TABLE workspace_members ADD CONSTRAINT workspace_members_role_check CHECK (role IN ('owner', 'editor', 'viewer'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_members');
        Schema::dropIfExists('workspaces');
    }
};
