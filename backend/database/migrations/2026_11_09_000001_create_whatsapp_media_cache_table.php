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
        Schema::create('whatsapp_media_cache', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->string('media_id')->index();
            $table->string('file_fingerprint', 64)->index();
            $table->string('mime_type')->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['channel_id', 'file_fingerprint'], 'uniq_whatsapp_media_channel_fingerprint');
            $table->index(['channel_id', 'expires_at'], 'idx_whatsapp_media_channel_expires');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_media_cache');
    }
};
