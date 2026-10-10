<?php

use App\Core\Database\AppendOnly;
use App\Core\Database\EnumCheck;
use App\Enums\ChangeOp;
use App\Enums\SyncEntity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every change to a synced row, in order. Devices pull "everything after seq N".
        // The key is a sequence number, not a UUID: its order is the point.
        Schema::create('change_log', function (Blueprint $table) {
            $table->bigInteger('seq')->primary();
            $table->foreignUuid('workspace_id')->constrained();
            $table->text('entity');
            $table->uuid('entity_id');
            $table->text('op');
            $table->integer('row_version')->nullable();
            // The device that made the change, when known.
            $table->foreignUuid('device_id')->nullable()->index()->constrained('user_devices');
            $table->timestamp('created_at')->useCurrent();

            // Pulling. Also covers the workspace_id foreign key.
            $table->index(['workspace_id', 'seq']);
            // The latest change to one row (who changed it last, for conflicts).
            $table->index(['entity', 'entity_id', 'seq']);
        });

        DB::statement('ALTER TABLE change_log ALTER COLUMN seq ADD GENERATED ALWAYS AS IDENTITY');
        EnumCheck::add('change_log', 'entity', SyncEntity::class);
        EnumCheck::add('change_log', 'op', ChangeOp::class);
        AppendOnly::apply('change_log');

        // Results of ops a device pushed, so a repeated op_id returns the same result
        // instead of applying twice (not in the design doc; makes offline ops idempotent).
        Schema::create('sync_ops', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->index()->constrained();
            $table->foreignUuid('device_id')->constrained('user_devices');
            $table->uuid('op_id');
            $table->jsonb('result');
            $table->timestamp('created_at')->useCurrent();

            // Also covers the device_id foreign key.
            $table->unique(['device_id', 'op_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_ops');
        Schema::dropIfExists('change_log');
    }
};
