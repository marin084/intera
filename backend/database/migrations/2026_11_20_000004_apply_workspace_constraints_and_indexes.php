<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected function withoutForeignKeys(callable $callback): void
    {
        $connection = DB::connection();
        $isSqlite = $connection->getDriverName() === 'sqlite';
        $transactionLevel = 0;

        if ($isSqlite && $connection->transactionLevel() > 0) {
            $transactionLevel = $connection->transactionLevel();
            for ($i = 0; $i < $transactionLevel; $i++) {
                $connection->commit();
            }
        }

        Schema::disableForeignKeyConstraints();

        try {
            $callback();
        } finally {
            Schema::enableForeignKeyConstraints();

            if ($isSqlite && $transactionLevel > 0) {
                for ($i = 0; $i < $transactionLevel; $i++) {
                    $connection->beginTransaction();
                }
            }
        }
    }

    protected function dropIndexIfExists(string $tableName, string $indexName): void
    {
        if (Schema::hasIndex($tableName, $indexName)) {
            Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                $table->dropIndex($indexName);
            });
        }
    }

    protected function dropUniqueIfExists(string $tableName, string $indexName): void
    {
        if (Schema::hasIndex($tableName, $indexName)) {
            Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                $table->dropUnique($indexName);
            });
        }
    }

    public function up(): void
    {
        $this->withoutForeignKeys(function (): void {
            $slug = env('APP_INITIAL_WORKSPACE_SLUG', 'principal');
            $initialWorkspaceId = DB::table('workspaces')->where('slug', $slug)->value('id');

            // 1. Teams
            $this->dropUniqueIfExists('teams', 'teams_name_unique');
            Schema::table('teams', function (Blueprint $table) use ($initialWorkspaceId): void {
                $col = $table->uuid('workspace_id');
                if ($initialWorkspaceId) {
                    $col->default($initialWorkspaceId);
                }
                $col->nullable(false)->change();
            });
            Schema::table('teams', function (Blueprint $table): void {
                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            });
            Schema::table('teams', function (Blueprint $table): void {
                $table->unique(['workspace_id', 'name'], 'uniq_teams_workspace_name');
            });

            // 2. Tags
            $this->dropUniqueIfExists('tags', 'tags_name_unique');
            $this->dropUniqueIfExists('tags', 'tags_slug_unique');
            Schema::table('tags', function (Blueprint $table) use ($initialWorkspaceId): void {
                $col = $table->uuid('workspace_id');
                if ($initialWorkspaceId) {
                    $col->default($initialWorkspaceId);
                }
                $col->nullable(false)->change();
            });
            Schema::table('tags', function (Blueprint $table): void {
                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            });
            Schema::table('tags', function (Blueprint $table): void {
                $table->unique(['workspace_id', 'name'], 'uniq_tags_workspace_name');
                $table->unique(['workspace_id', 'slug'], 'uniq_tags_workspace_slug');
            });

            // 3. Canned Responses
            Schema::table('canned_responses', function (Blueprint $table) use ($initialWorkspaceId): void {
                $col = $table->uuid('workspace_id');
                if ($initialWorkspaceId) {
                    $col->default($initialWorkspaceId);
                }
                $col->nullable(false)->change();
            });
            Schema::table('canned_responses', function (Blueprint $table): void {
                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            });
            Schema::table('canned_responses', function (Blueprint $table): void {
                $table->unique(['workspace_id', 'shortcut'], 'uniq_canned_responses_workspace_shortcut');
            });

            // 4. Channels
            Schema::table('channels', function (Blueprint $table) use ($initialWorkspaceId): void {
                $col = $table->uuid('workspace_id');
                if ($initialWorkspaceId) {
                    $col->default($initialWorkspaceId);
                }
                $col->nullable(false)->change();
            });
            Schema::table('channels', function (Blueprint $table): void {
                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            });
            Schema::table('channels', function (Blueprint $table): void {
                $table->index(['workspace_id', 'type'], 'idx_channels_workspace_type');
            });

            // 5. Contacts
            Schema::table('contacts', function (Blueprint $table) use ($initialWorkspaceId): void {
                $col = $table->uuid('workspace_id');
                if ($initialWorkspaceId) {
                    $col->default($initialWorkspaceId);
                }
                $col->nullable(false)->change();
            });
            Schema::table('contacts', function (Blueprint $table): void {
                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            });
            Schema::table('contacts', function (Blueprint $table): void {
                $table->index(['workspace_id', 'phone'], 'idx_contacts_workspace_phone');
            });

            // 6. Flows
            Schema::table('flows', function (Blueprint $table) use ($initialWorkspaceId): void {
                $col = $table->uuid('workspace_id');
                if ($initialWorkspaceId) {
                    $col->default($initialWorkspaceId);
                }
                $col->nullable(false)->change();
            });
            Schema::table('flows', function (Blueprint $table): void {
                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            });
            Schema::table('flows', function (Blueprint $table): void {
                $table->index(['workspace_id', 'status'], 'idx_flows_workspace_status');
            });

            // 7. Conversations
            Schema::table('conversations', function (Blueprint $table) use ($initialWorkspaceId): void {
                $col = $table->uuid('workspace_id');
                if ($initialWorkspaceId) {
                    $col->default($initialWorkspaceId);
                }
                $col->nullable(false)->change();
            });
            Schema::table('conversations', function (Blueprint $table): void {
                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            });
            Schema::table('conversations', function (Blueprint $table): void {
                $table->index(['workspace_id', 'status', 'queued_at'], 'idx_conversations_workspace_status_queued');
            });

            // 8. Saved Funnels
            Schema::table('saved_funnels', function (Blueprint $table) use ($initialWorkspaceId): void {
                $col = $table->uuid('workspace_id');
                if ($initialWorkspaceId) {
                    $col->default($initialWorkspaceId);
                }
                $col->nullable(false)->change();
            });
            Schema::table('saved_funnels', function (Blueprint $table): void {
                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            });
            Schema::table('saved_funnels', function (Blueprint $table): void {
                $table->index(['workspace_id', 'flow_id'], 'idx_saved_funnels_workspace_flow');
            });

            // 9. Agent Presence Sessions
            $this->dropIndexIfExists('agent_presence_sessions', 'agent_presence_user_period_idx');
            Schema::table('agent_presence_sessions', function (Blueprint $table) use ($initialWorkspaceId): void {
                $col = $table->uuid('workspace_id');
                if ($initialWorkspaceId) {
                    $col->default($initialWorkspaceId);
                }
                $col->nullable(false)->change();
            });
            Schema::table('agent_presence_sessions', function (Blueprint $table): void {
                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            });
            Schema::table('agent_presence_sessions', function (Blueprint $table): void {
                $table->index(['workspace_id', 'user_id', 'started_at', 'ended_at'], 'idx_agent_presence_ws_user_period');
                $table->index(['workspace_id', 'user_id', 'session_key', 'ended_at'], 'idx_agent_presence_ws_lookup');
                $table->index(['workspace_id', 'started_at', 'ended_at'], 'idx_agent_presence_ws_period');
            });

            // 10. Audit Logs
            Schema::table('audit_logs', function (Blueprint $table): void {
                $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
            });
            Schema::table('audit_logs', function (Blueprint $table): void {
                $table->index(['workspace_id', 'created_at'], 'idx_audit_logs_workspace_created');
            });
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('workspaces') && DB::table('workspaces')->count() > 1) {
            throw new RuntimeException(
                'ABORTADO: Existen múltiples workspaces con datos activos. '.
                'Un rollback directo mezclaría o destruiría irrevocablemente los datos de diferentes empresas. '.
                'Ejecute un backup completo y consolide o archive los workspaces secundarios antes de revertir.'
            );
        }

        try {
            $this->withoutForeignKeys(function (): void {
                // Drop indexes first
                $this->dropIndexIfExists('audit_logs', 'idx_audit_logs_workspace_created');

                $this->dropIndexIfExists('agent_presence_sessions', 'idx_agent_presence_ws_user_period');
                $this->dropIndexIfExists('agent_presence_sessions', 'idx_agent_presence_ws_lookup');
                $this->dropIndexIfExists('agent_presence_sessions', 'idx_agent_presence_ws_period');

                $this->dropIndexIfExists('saved_funnels', 'idx_saved_funnels_workspace_flow');
                $this->dropIndexIfExists('conversations', 'idx_conversations_workspace_status_queued');
                $this->dropIndexIfExists('flows', 'idx_flows_workspace_status');
                $this->dropIndexIfExists('contacts', 'idx_contacts_workspace_phone');
                $this->dropIndexIfExists('channels', 'idx_channels_workspace_type');

                $this->dropUniqueIfExists('canned_responses', 'uniq_canned_responses_workspace_shortcut');
                $this->dropUniqueIfExists('tags', 'uniq_tags_workspace_name');
                $this->dropUniqueIfExists('tags', 'uniq_tags_workspace_slug');
                $this->dropUniqueIfExists('teams', 'uniq_teams_workspace_name');

                // 10. Audit Logs
                Schema::table('audit_logs', function (Blueprint $table): void {
                    $table->dropForeign(['workspace_id']);
                });

                // 9. Agent Presence Sessions
                Schema::table('agent_presence_sessions', function (Blueprint $table): void {
                    $table->dropForeign(['workspace_id']);
                });
                if (! Schema::hasIndex('agent_presence_sessions', 'agent_presence_user_period_idx')) {
                    Schema::table('agent_presence_sessions', function (Blueprint $table): void {
                        $table->index(['user_id', 'started_at', 'ended_at'], 'agent_presence_user_period_idx');
                    });
                }
                Schema::table('agent_presence_sessions', function (Blueprint $table): void {
                    $table->uuid('workspace_id')->nullable()->change();
                });

                // 8. Saved Funnels
                Schema::table('saved_funnels', function (Blueprint $table): void {
                    $table->dropForeign(['workspace_id']);
                });
                Schema::table('saved_funnels', function (Blueprint $table): void {
                    $table->uuid('workspace_id')->nullable()->change();
                });

                // 7. Conversations
                Schema::table('conversations', function (Blueprint $table): void {
                    $table->dropForeign(['workspace_id']);
                });
                Schema::table('conversations', function (Blueprint $table): void {
                    $table->uuid('workspace_id')->nullable()->change();
                });

                // 6. Flows
                Schema::table('flows', function (Blueprint $table): void {
                    $table->dropForeign(['workspace_id']);
                });
                Schema::table('flows', function (Blueprint $table): void {
                    $table->uuid('workspace_id')->nullable()->change();
                });

                // 5. Contacts
                Schema::table('contacts', function (Blueprint $table): void {
                    $table->dropForeign(['workspace_id']);
                });
                Schema::table('contacts', function (Blueprint $table): void {
                    $table->uuid('workspace_id')->nullable()->change();
                });

                // 4. Channels
                Schema::table('channels', function (Blueprint $table): void {
                    $table->dropForeign(['workspace_id']);
                });
                Schema::table('channels', function (Blueprint $table): void {
                    $table->uuid('workspace_id')->nullable()->change();
                });

                // 3. Canned Responses
                Schema::table('canned_responses', function (Blueprint $table): void {
                    $table->dropForeign(['workspace_id']);
                });
                Schema::table('canned_responses', function (Blueprint $table): void {
                    $table->uuid('workspace_id')->nullable()->change();
                });

                // 2. Tags
                Schema::table('tags', function (Blueprint $table): void {
                    $table->dropForeign(['workspace_id']);
                });
                if (! Schema::hasIndex('tags', 'tags_name_unique')) {
                    Schema::table('tags', function (Blueprint $table): void {
                        $table->unique('name', 'tags_name_unique');
                    });
                }
                if (! Schema::hasIndex('tags', 'tags_slug_unique')) {
                    Schema::table('tags', function (Blueprint $table): void {
                        $table->unique('slug', 'tags_slug_unique');
                    });
                }
                Schema::table('tags', function (Blueprint $table): void {
                    $table->uuid('workspace_id')->nullable()->change();
                });

                // 1. Teams
                Schema::table('teams', function (Blueprint $table): void {
                    $table->dropForeign(['workspace_id']);
                });
                if (! Schema::hasIndex('teams', 'teams_name_unique')) {
                    Schema::table('teams', function (Blueprint $table): void {
                        $table->unique('name', 'teams_name_unique');
                    });
                }
                Schema::table('teams', function (Blueprint $table): void {
                    $table->uuid('workspace_id')->nullable()->change();
                });
            });
        } catch (Throwable $e) {
            fwrite(STDERR, "\n\n>>> M4 DOWN EXCEPTION: ".$e->getMessage().' at '.$e->getFile().':'.$e->getLine()."\n\n");
            throw $e;
        }
    }
};
