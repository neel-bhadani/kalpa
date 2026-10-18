<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The exact files of a model per engine (e.g. a GGUF for wllama, an MLC build for WebLLM,
        // an Ollama tag), with url, bytes, sha256 and the RAM it needs once loaded.
        Schema::table('model_registry', function (Blueprint $table) {
            $table->jsonb('builds')->default('{}');
        });
    }

    public function down(): void
    {
        Schema::table('model_registry', function (Blueprint $table) {
            $table->dropColumn('builds');
        });
    }
};
