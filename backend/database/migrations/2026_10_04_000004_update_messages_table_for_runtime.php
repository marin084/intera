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
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignUuid('flow_execution_id')->nullable()->after('conversation_id')->constrained('flow_executions')->nullOnDelete();
            $table->foreignUuid('flow_node_id')->nullable()->after('flow_execution_id')->constrained('flow_nodes')->nullOnDelete();
            $table->unique(['conversation_id', 'external_message_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique(['conversation_id', 'external_message_id']);
            $table->dropForeign(['flow_execution_id']);
            $table->dropForeign(['flow_node_id']);
            $table->dropColumn(['flow_execution_id', 'flow_node_id']);
        });
    }
};
