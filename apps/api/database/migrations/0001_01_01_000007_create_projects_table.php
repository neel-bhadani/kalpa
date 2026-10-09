<?php

use App\Core\Database\EnumCheck;
use App\Enums\AspectRatio;
use App\Enums\ProjectStatus;
use App\Enums\ProjectStyle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->index()->constrained();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('style')->default(ProjectStyle::Mixed->value);
            $table->text('aspect_ratio')->default(AspectRatio::Wide->value);
            // Foreign key to files is added in P0 step 6.
            $table->uuid('cover_file_id')->nullable();
            $table->text('status')->default(ProjectStatus::Active->value);
            $table->timestamp('last_opened_at')->nullable();
            $table->foreignUuid('created_by')->index()->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });

        EnumCheck::add('projects', 'style', ProjectStyle::class);
        EnumCheck::add('projects', 'aspect_ratio', AspectRatio::class);
        EnumCheck::add('projects', 'status', ProjectStatus::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
