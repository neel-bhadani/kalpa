<?php

use App\Core\Database\EnumCheck;
use App\Enums\WorkspacePlan;
use App\Enums\WorkspaceRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

        EnumCheck::add('workspaces', 'plan', WorkspacePlan::class);

        Schema::create('workspace_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->index()->constrained();
            $table->foreignUuid('user_id')->index()->constrained();
            $table->text('role');
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
        });

        EnumCheck::add('workspace_members', 'role', WorkspaceRole::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_members');
        Schema::dropIfExists('workspaces');
    }
};
