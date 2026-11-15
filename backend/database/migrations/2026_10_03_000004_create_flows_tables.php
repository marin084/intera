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
        Schema::create('flows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('draft')->index(); // draft, published, archived
            $table->json('channel_applicability')->nullable(); // e.g. ["webchat"]
            $table->uuid('active_version_id')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('flow_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('flow_id')->constrained('flows')->cascadeOnDelete();
            $table->unsignedInteger('version_number')->default(1)->index();
            $table->string('status')->default('draft')->index(); // draft, published, archived
            $table->text('description')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['flow_id', 'version_number']);
        });

        Schema::table('flows', function (Blueprint $table) {
            $table->foreign('active_version_id')->references('id')->on('flow_versions')->nullOnDelete();
        });

        Schema::create('flow_nodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('flow_version_id')->constrained('flow_versions')->cascadeOnDelete();
            $table->string('node_key')->index();
            $table->string('type')->index(); // start, message, question, condition, transfer_to_agent, end
            $table->string('name');
            $table->json('configuration')->nullable();
            $table->timestamps();

            $table->unique(['flow_version_id', 'node_key']);
        });

        Schema::create('flow_edges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('flow_version_id')->constrained('flow_versions')->cascadeOnDelete();
            $table->string('source_node_key')->index();
            $table->string('target_node_key')->index();
            $table->string('condition_handle')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flow_edges');
        Schema::dropIfExists('flow_nodes');
        Schema::table('flows', function (Blueprint $table) {
            $table->dropForeign(['active_version_id']);
        });
        Schema::dropIfExists('flow_versions');
        Schema::dropIfExists('flows');
    }
};
