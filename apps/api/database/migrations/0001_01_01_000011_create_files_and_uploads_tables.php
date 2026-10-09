<?php

use App\Core\Database\EnumCheck;
use App\Enums\UploadStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Content-addressed: one row per distinct file content (SHA-256) in a workspace.
        Schema::create('files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained();
            $table->char('sha256', 64);
            $table->bigInteger('size_bytes');
            $table->text('mime');
            $table->text('storage_disk');
            // ws/<workspace_id>/<aa>/<bb>/<sha256>
            $table->text('path');
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->bigInteger('duration_ms')->nullable();
            $table->integer('ref_count')->default(0);
            // Set once a background job has re-hashed the stored bytes (P0 step 7).
            $table->timestamp('verified_at')->nullable();
            $table->foreignUuid('created_by')->index()->constrained('users');
            $table->timestamps();

            // Dedupe within a workspace only. Also covers the workspace_id foreign key.
            $table->unique(['workspace_id', 'sha256']);
        });

        DB::statement("ALTER TABLE files ADD CONSTRAINT files_sha256_check CHECK (sha256 ~ '^[0-9a-f]{64}$')");

        // Multipart uploads in progress (not in the design doc; tracks resumable uploads).
        Schema::create('uploads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->index()->constrained();
            $table->foreignUuid('user_id')->index()->constrained();
            $table->string('file_name');
            $table->bigInteger('size_bytes');
            $table->text('mime');
            $table->char('sha256', 64);
            $table->integer('part_size');
            $table->text('s3_upload_id');
            $table->text('path');
            $table->text('status')->default(UploadStatus::Pending->value);
            $table->foreignUuid('file_id')->nullable()->index()->constrained('files')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();

            // For the expiry sweep.
            $table->index(['status', 'expires_at']);
        });

        DB::statement("ALTER TABLE uploads ADD CONSTRAINT uploads_sha256_check CHECK (sha256 ~ '^[0-9a-f]{64}$')");
        EnumCheck::add('uploads', 'status', UploadStatus::class);

        Schema::table('projects', function (Blueprint $table) {
            $table->index('cover_file_id');
            $table->foreign('cover_file_id')->references('id')->on('files')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['cover_file_id']);
            $table->dropIndex(['cover_file_id']);
        });

        Schema::dropIfExists('uploads');
        Schema::dropIfExists('files');
    }
};
