<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained();
            // A module key from config/modules.php (checked in code, since modules come and go).
            $table->text('module');
            $table->boolean('enabled')->default(true);
            $table->jsonb('settings')->default('{}');
            $table->timestamps();

            // Also covers the workspace_id foreign key.
            $table->unique(['workspace_id', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_settings');
    }
};
