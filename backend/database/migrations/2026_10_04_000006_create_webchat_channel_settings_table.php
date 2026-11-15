<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('webchat_channel_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('channel_id')->unique()->constrained('channels')->cascadeOnDelete();
            $table->string('public_key', 64)->unique();
            $table->jsonb('allowed_origins')->default('[]');
            $table->string('display_name');
            $table->string('accent_color', 10)->nullable();
            $table->string('launcher_position', 20)->default('bottom-right');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webchat_channel_settings');
    }
};
