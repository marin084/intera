<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var list<string> */
    protected array $directTables = [
        'channels',
        'contacts',
        'flows',
        'conversations',
        'teams',
        'tags',
        'canned_responses',
        'saved_funnels',
        'agent_presence_sessions',
    ];

    protected function resolveInitialOwnerId(): ?int
    {
        if (! Schema::hasTable('users')) {
            return null;
        }

        if (Schema::hasTable('roles') && Schema::hasTable('role_user')) {
            $roleHierarchy = ['super_admin', 'admin', 'supervisor', 'flow_manager', 'agent'];

            foreach ($roleHierarchy as $roleName) {
                $userId = DB::table('role_user')
                    ->join('roles', 'roles.id', '=', 'role_user.role_id')
                    ->join('users', 'users.id', '=', 'role_user.user_id')
                    ->where('roles.name', $roleName)
                    ->orderBy('users.id')
                    ->value('users.id');

                if ($userId !== null) {
                    return (int) $userId;
                }
            }
        }

        $firstUserId = DB::table('users')->orderBy('id')->value('id');

        return $firstUserId !== null ? (int) $firstUserId : null;
    }

    public function up(): void
    {
        $slug = env('APP_INITIAL_WORKSPACE_SLUG', 'principal');
        $name = env('APP_INITIAL_WORKSPACE_NAME', 'Espacio Principal');

        $workspace = DB::table('workspaces')->where('slug', $slug)->first();

        if (! $workspace) {
            $initialOwnerId = $this->resolveInitialOwnerId();
            $workspaceId = (string) Str::uuid();

            DB::table('workspaces')->insert([
                'id' => $workspaceId,
                'name' => $name,
                'slug' => $slug,
                'status' => 'active',
                'initial_owner_id' => $initialOwnerId,
                'configuration' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $workspaceId = $workspace->id;

            if (empty($workspace->initial_owner_id)) {
                $initialOwnerId = $this->resolveInitialOwnerId();
                if ($initialOwnerId !== null) {
                    DB::table('workspaces')->where('id', $workspaceId)->update(['initial_owner_id' => $initialOwnerId]);
                }
            }
        }

        // 1. Backfill direct tables
        foreach ($this->directTables as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'workspace_id')) {
                DB::table($tableName)->whereNull('workspace_id')->update(['workspace_id' => $workspaceId]);
            }
        }

        // 2. Backfill audit_logs (business resources get workspace_id, platform logs remain null)
        if (Schema::hasTable('audit_logs') && Schema::hasColumn('audit_logs', 'workspace_id')) {
            $businessEntityTypes = [
                'Flow', 'Channel', 'Conversation', 'Team', 'Tag', 'CannedResponse', 'SavedFunnel',
                'App\\Models\\Flow', 'App\\Models\\Channel', 'App\\Models\\Conversation', 'App\\Models\\Team',
                'App\\Models\\Tag', 'App\\Models\\CannedResponse', 'App\\Models\\SavedFunnel',
                'flow', 'channel', 'conversation', 'team', 'tag', 'canned_response', 'saved_funnel',
            ];

            DB::table('audit_logs')
                ->whereNull('workspace_id')
                ->where(function ($query) use ($businessEntityTypes): void {
                    $query->whereIn('entity_type', $businessEntityTypes)
                        ->orWhere('action', 'like', 'flow.%')
                        ->orWhere('action', 'like', 'channel.%')
                        ->orWhere('action', 'like', 'conversation.%')
                        ->orWhere('action', 'like', 'team.%')
                        ->orWhere('action', 'like', 'tag.%')
                        ->orWhere('action', 'like', 'canned_response.%')
                        ->orWhere('action', 'like', 'saved_funnel.%')
                        ->orWhere('action', 'like', 'inbox.%');
                })
                ->update(['workspace_id' => $workspaceId]);
        }

        // 3. Backfill users and workspace_user memberships
        if (Schema::hasTable('users') && Schema::hasTable('workspace_user') && Schema::hasTable('roles')) {
            $agentRoleId = DB::table('roles')->where('name', 'agent')->value('id');
            $users = DB::table('users')->get();

            foreach ($users as $user) {
                $userRoles = DB::table('role_user')
                    ->join('roles', 'roles.id', '=', 'role_user.role_id')
                    ->where('role_user.user_id', $user->id)
                    ->pluck('roles.name', 'roles.id');

                $assignedRoleId = null;
                $hasSuperAdmin = false;

                foreach (['super_admin', 'admin', 'supervisor', 'flow_manager', 'agent'] as $roleHierarchy) {
                    $matchingRoleId = $userRoles->search($roleHierarchy);
                    if ($matchingRoleId !== false) {
                        $assignedRoleId = $matchingRoleId;
                        if ($roleHierarchy === 'super_admin') {
                            $hasSuperAdmin = true;
                        }
                        break;
                    }
                }

                if ($assignedRoleId) {
                    DB::table('workspace_user')->updateOrInsert(
                        ['workspace_id' => $workspaceId, 'user_id' => $user->id],
                        [
                            'role_id' => $assignedRoleId,
                            'is_active' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                } elseif ($agentRoleId) {
                    DB::table('workspace_user')->updateOrInsert(
                        ['workspace_id' => $workspaceId, 'user_id' => $user->id],
                        [
                            'role_id' => $agentRoleId,
                            'is_active' => false,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }

                $userUpdates = [];
                if (empty($user->active_workspace_id)) {
                    $userUpdates['active_workspace_id'] = $workspaceId;
                }
                if ($hasSuperAdmin) {
                    $userUpdates['is_platform_admin'] = true;
                }
                if (! empty($userUpdates)) {
                    DB::table('users')->where('id', $user->id)->update($userUpdates);
                }
            }
        }
    }

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

    public function down(): void
    {
        if (Schema::hasTable('workspaces') && DB::table('workspaces')->count() > 1) {
            throw new RuntimeException(
                'ABORTADO: Existen múltiples workspaces con datos activos. '.
                'Un rollback directo mezclaría o destruiría irrevocablemente los datos de diferentes empresas. '.
                'Ejecute un backup completo y consolide o archive los workspaces secundarios antes de revertir.'
            );
        }

        $this->withoutForeignKeys(function (): void {
            $slug = env('APP_INITIAL_WORKSPACE_SLUG', 'principal');
            $initialWorkspaceId = DB::table('workspaces')->where('slug', $slug)->value('id');

            if ($initialWorkspaceId) {
                DB::table('workspace_user')->where('workspace_id', $initialWorkspaceId)->delete();

                foreach ($this->directTables as $tableName) {
                    if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'workspace_id')) {
                        DB::table($tableName)->where('workspace_id', $initialWorkspaceId)->update(['workspace_id' => null]);
                    }
                }

                if (Schema::hasTable('audit_logs') && Schema::hasColumn('audit_logs', 'workspace_id')) {
                    DB::table('audit_logs')->where('workspace_id', $initialWorkspaceId)->update(['workspace_id' => null]);
                }

                DB::table('users')->where('active_workspace_id', $initialWorkspaceId)->update([
                    'active_workspace_id' => null,
                    'is_platform_admin' => false,
                ]);

                DB::table('workspaces')->where('id', $initialWorkspaceId)->delete();
            }
        });
    }
};
