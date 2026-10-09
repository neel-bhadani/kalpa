<?php

use App\Core\Database\EnumCheck;
use App\Enums\DevicePlatform;
use App\Enums\DeviceTier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->index()->constrained();
            // Made by the client once and kept (localStorage on the web).
            $table->uuid('client_id');
            $table->text('platform');
            $table->string('name');
            $table->jsonb('capabilities')->default('{}');
            $table->text('tier');
            $table->text('tier_override')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'client_id']);
        });

        EnumCheck::add('user_devices', 'platform', DevicePlatform::class);
        EnumCheck::add('user_devices', 'tier', DeviceTier::class);
        EnumCheck::add('user_devices', 'tier_override', DeviceTier::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_devices');
    }
};
