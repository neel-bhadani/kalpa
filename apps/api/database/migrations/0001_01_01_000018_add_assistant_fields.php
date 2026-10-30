<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The one-line reason an assistant gives for a step ("Adding a midpoint twist so act 2 doesn't sag").
        Schema::table('ai_steps', function (Blueprint $table) {
            $table->text('reason')->nullable();
        });

        // Which assistant step changed the project memory (null for a person's change).
        Schema::table('project_memory_versions', function (Blueprint $table) {
            $table->foreignUuid('ai_step_id')->nullable()->index()->constrained('ai_steps');
        });
    }

    public function down(): void
    {
        Schema::table('project_memory_versions', fn (Blueprint $table) => $table->dropConstrainedForeignId('ai_step_id'));
        Schema::table('ai_steps', fn (Blueprint $table) => $table->dropColumn('reason'));
    }
};
