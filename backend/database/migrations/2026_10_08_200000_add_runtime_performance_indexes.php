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
        Schema::table('conversations', function (Blueprint $table) {
            $table->index(['channel_id', 'contact_id', 'status'], 'idx_conversations_channel_contact_status');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->index(['conversation_id', 'sender_type', 'sent_at'], 'idx_messages_conv_sender_sent');
        });

        Schema::table('flow_executions', function (Blueprint $table) {
            $table->index(['conversation_id', 'started_at'], 'idx_flow_executions_conv_started');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flow_executions', function (Blueprint $table) {
            $table->dropIndex('idx_flow_executions_conv_started');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('idx_messages_conv_sender_sent');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('idx_conversations_channel_contact_status');
        });
    }
};
