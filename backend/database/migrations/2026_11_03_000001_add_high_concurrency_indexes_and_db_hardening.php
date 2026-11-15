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
        // 1. Conversations table indexes for hot path ingress and inbox query acceleration
        Schema::table('conversations', function (Blueprint $table) {
            if (! Schema::hasIndex('conversations', 'idx_conversations_channel_contact_status')) {
                $table->index(['channel_id', 'contact_id', 'status'], 'idx_conversations_channel_contact_status');
            }

            if (! Schema::hasIndex('conversations', 'idx_conversations_status_assigned_last_msg')) {
                $table->index(['status', 'assigned_user_id', 'last_message_at'], 'idx_conversations_status_assigned_last_msg');
            }

            if (! Schema::hasIndex('conversations', 'idx_conversations_channel_status_last_msg')) {
                $table->index(['channel_id', 'status', 'last_message_at'], 'idx_conversations_channel_status_last_msg');
            }
        });

        // 2. Flow executions table index for active execution lookups
        Schema::table('flow_executions', function (Blueprint $table) {
            if (! Schema::hasIndex('flow_executions', 'idx_flow_executions_conv_status') &&
                ! Schema::hasIndex('flow_executions', 'flow_executions_conversation_id_status_index')) {
                $table->index(['conversation_id', 'status'], 'idx_flow_executions_conv_status');
            }
        });

        // 3. Flow execution steps table index for execution step history queries
        Schema::table('flow_execution_steps', function (Blueprint $table) {
            if (! Schema::hasIndex('flow_execution_steps', 'idx_flow_execution_steps_exec_created')) {
                $table->index(['flow_execution_id', 'created_at'], 'idx_flow_execution_steps_exec_created');
            }
        });

        // 4. Webchat sessions table index for session prune command acceleration
        Schema::table('webchat_sessions', function (Blueprint $table) {
            if (! Schema::hasIndex('webchat_sessions', 'idx_webchat_sessions_expires_at') &&
                ! Schema::hasIndex('webchat_sessions', 'webchat_sessions_expires_at_index')) {
                $table->index(['expires_at'], 'idx_webchat_sessions_expires_at');
            }
        });

        // 5. Audit logs table composite and temporal indexes
        Schema::table('audit_logs', function (Blueprint $table) {
            if (! Schema::hasIndex('audit_logs', 'idx_audit_logs_entity_created')) {
                $table->index(['entity_type', 'entity_id', 'created_at'], 'idx_audit_logs_entity_created');
            }

            if (! Schema::hasIndex('audit_logs', 'idx_audit_logs_created_at') &&
                ! Schema::hasIndex('audit_logs', 'audit_logs_created_at_index')) {
                $table->index(['created_at'], 'idx_audit_logs_created_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            if (Schema::hasIndex('audit_logs', 'idx_audit_logs_entity_created')) {
                $table->dropIndex('idx_audit_logs_entity_created');
            }
            if (Schema::hasIndex('audit_logs', 'idx_audit_logs_created_at')) {
                $table->dropIndex('idx_audit_logs_created_at');
            }
        });

        Schema::table('webchat_sessions', function (Blueprint $table) {
            if (Schema::hasIndex('webchat_sessions', 'idx_webchat_sessions_expires_at')) {
                $table->dropIndex('idx_webchat_sessions_expires_at');
            }
        });

        Schema::table('flow_execution_steps', function (Blueprint $table) {
            if (Schema::hasIndex('flow_execution_steps', 'idx_flow_execution_steps_exec_created')) {
                $table->dropIndex('idx_flow_execution_steps_exec_created');
            }
        });

        Schema::table('flow_executions', function (Blueprint $table) {
            if (Schema::hasIndex('flow_executions', 'idx_flow_executions_conv_status')) {
                $table->dropIndex('idx_flow_executions_conv_status');
            }
        });

        Schema::table('conversations', function (Blueprint $table) {
            if (Schema::hasIndex('conversations', 'idx_conversations_channel_status_last_msg')) {
                $table->dropIndex('idx_conversations_channel_status_last_msg');
            }
            if (Schema::hasIndex('conversations', 'idx_conversations_status_assigned_last_msg')) {
                $table->dropIndex('idx_conversations_status_assigned_last_msg');
            }
        });
    }
};
