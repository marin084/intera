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
        Schema::create('flow_executions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignUuid('flow_id')->constrained('flows')->cascadeOnDelete();
            $table->foreignUuid('flow_version_id')->constrained('flow_versions')->cascadeOnDelete();
            $table->foreignUuid('current_node_id')->nullable()->constrained('flow_nodes')->nullOnDelete();
            $table->string('status')->default('running')->index(); // running, waiting_input, waiting_agent, completed, failed, cancelled
            $table->json('context')->nullable();
            $table->timestamp('started_at')->useCurrent()->index();
            $table->timestamp('waiting_since')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_code')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'status']);
        });

        Schema::create('flow_execution_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('flow_execution_id')->constrained('flow_executions')->cascadeOnDelete();
            $table->foreignUuid('flow_node_id')->nullable()->constrained('flow_nodes')->cascadeOnDelete();
            $table->string('node_type')->index();
            $table->string('status')->default('entered')->index(); // entered, completed, waiting, failed, skipped
            $table->timestamp('entered_at')->useCurrent()->index();
            $table->timestamp('completed_at')->nullable();
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->string('error_code')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['flow_execution_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flow_execution_steps');
        Schema::dropIfExists('flow_executions');
    }
};
