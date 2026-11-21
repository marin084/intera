<?php

namespace Tests\Feature;

use App\Models\AgentPresenceSession;
use App\Models\AuditLog;
use App\Models\CannedResponse;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\Role;
use App\Models\SavedFunnel;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class MultitenancyPhase1SchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Roll back through a named multitenancy migration without coupling the
     * test to the number of migrations added after the original phase.
     */
    protected function rollbackThroughMultitenancyMigration(string $migration): int
    {
        $lastBatch = DB::table('migrations')->max('batch');
        $latestBatch = DB::table('migrations')
            ->where('batch', $lastBatch)
            ->orderByDesc('migration')
            ->pluck('migration')
            ->values();

        $position = $latestBatch->search($migration, true);

        if ($position === false) {
            throw new RuntimeException("La migración {$migration} no está aplicada en el último batch.");
        }

        return Artisan::call('migrate:rollback', [
            '--step' => $position + 1,
            '--force' => true,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    /** 1. Creación de workspace */
    public function test_01_workspace_creation(): void
    {
        $workspace = Workspace::create([
            'name' => 'Acme Corp',
            'slug' => 'acme-corp',
            'status' => 'active',
            'configuration' => ['timezone' => 'UTC', 'features' => ['funnels' => true]],
        ]);

        $this->assertDatabaseHas('workspaces', [
            'id' => $workspace->id,
            'name' => 'Acme Corp',
            'slug' => 'acme-corp',
            'status' => 'active',
        ]);

        $this->assertTrue(Str::isUuid($workspace->id));
        $this->assertIsArray($workspace->configuration);
        $this->assertSame('UTC', $workspace->configuration['timezone']);
    }

    /** 2. Creación de membresía y protección contra duplicados */
    public function test_02_workspace_membership_creation_and_duplicate_protection(): void
    {
        $workspace = Workspace::create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        $user = User::factory()->create();
        $role = Role::where('name', 'agent')->firstOrFail();

        $membership = WorkspaceUser::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('workspace_user', [
            'id' => $membership->id,
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        // Intentar duplicar membresía en el mismo workspace debe fallar por unicidad
        $this->expectException(QueryException::class);
        WorkspaceUser::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'is_active' => false,
        ]);
    }

    /** 3. Foreign keys y comportamiento de cascada */
    public function test_03_foreign_keys_and_cascade_behavior(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create([
            'name' => 'Tenant Cascade',
            'slug' => 'tenant-cascade',
            'initial_owner_id' => $owner->id,
        ]);

        $owner->update(['active_workspace_id' => $workspace->id]);

        $channel = Channel::create([
            'workspace_id' => $workspace->id,
            'name' => 'Test Channel',
            'type' => 'webchat',
        ]);

        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'display_name' => 'Test Contact',
            'phone' => '+50688888888',
        ]);

        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
        ]);

        // Verificamos que existen
        $this->assertDatabaseHas('channels', ['id' => $channel->id]);
        $this->assertDatabaseHas('conversations', ['id' => $conversation->id]);

        // Eliminar workspace debe eliminar en cascada los recursos directos
        $workspace->delete();

        $this->assertDatabaseMissing('channels', ['id' => $channel->id]);
        $this->assertDatabaseMissing('contacts', ['id' => $contact->id]);
        $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);

        // El active_workspace_id del usuario debe quedar en null (nullOnDelete)
        $owner->refresh();
        $this->assertNull($owner->active_workspace_id);
    }

    /** 4. Backfill de todos los datos al workspace inicial */
    public function test_04_backfill_of_all_existing_data(): void
    {
        $initialWorkspaceId = Workspace::initialWorkspaceId();
        $this->assertNotNull($initialWorkspaceId);

        $initialWorkspace = Workspace::find($initialWorkspaceId);
        $this->assertSame('principal', $initialWorkspace->slug);
        $this->assertSame('Espacio Principal', $initialWorkspace->name);

        // Crear entidades sin workspace_id explícito para comprobar auto-asignación al workspace inicial
        $channel = Channel::create(['name' => 'Support Webchat', 'type' => 'webchat']);
        $this->assertSame($initialWorkspaceId, $channel->workspace_id);

        $contact = Contact::create(['display_name' => 'John Doe', 'phone' => '+1234567890']);
        $this->assertSame($initialWorkspaceId, $contact->workspace_id);

        $flow = Flow::create(['name' => 'Onboarding Flow']);
        $this->assertSame($initialWorkspaceId, $flow->workspace_id);

        $team = Team::create(['name' => 'Ventas']);
        $this->assertSame($initialWorkspaceId, $team->workspace_id);

        $tag = Tag::create(['name' => 'VIP Tag']);
        $this->assertSame($initialWorkspaceId, $tag->workspace_id);

        $canned = CannedResponse::create(['title' => 'Greeting', 'content' => 'Hello!']);
        $this->assertSame($initialWorkspaceId, $canned->workspace_id);

        $funnel = SavedFunnel::create(['name' => 'Conversion', 'flow_id' => $flow->id, 'steps' => []]);
        $this->assertSame($initialWorkspaceId, $funnel->workspace_id);
    }

    /** 5. Backfill de usuarios con varios roles: asignación del rol de mayor jerarquía */
    public function test_05_users_with_multiple_roles_assigns_highest_hierarchy(): void
    {
        $initialWorkspaceId = Workspace::initialWorkspaceId();
        $user = User::factory()->create();

        $superAdminRole = Role::where('name', 'super_admin')->firstOrFail();
        $agentRole = Role::where('name', 'agent')->firstOrFail();

        // Asignamos dos roles en role_user (super_admin y agent)
        DB::table('role_user')->insert([
            ['user_id' => $user->id, 'role_id' => $superAdminRole->id],
            ['user_id' => $user->id, 'role_id' => $agentRole->id],
        ]);

        // Forzar lógica del backfill para este usuario
        $migration = require database_path('migrations/2026_11_20_000003_backfill_initial_workspace_data.php');
        $migration->up();

        $membership = WorkspaceUser::where('user_id', $user->id)
            ->where('workspace_id', $initialWorkspaceId)
            ->first();

        $this->assertNotNull($membership);
        $this->assertSame($superAdminRole->id, $membership->role_id);
        $this->assertTrue($membership->is_active);

        $user->refresh();
        $this->assertTrue($user->is_platform_admin);
        $this->assertSame($initialWorkspaceId, $user->active_workspace_id);
    }

    /** 6. Backfill de usuarios sin roles: asignación de membresía agent inactiva */
    public function test_06_users_without_roles_assigns_inactive_agent_membership(): void
    {
        $initialWorkspaceId = Workspace::initialWorkspaceId();
        $userWithoutRoles = User::factory()->create();

        // Sin roles en role_user
        $this->assertDatabaseMissing('role_user', ['user_id' => $userWithoutRoles->id]);

        $migration = require database_path('migrations/2026_11_20_000003_backfill_initial_workspace_data.php');
        $migration->up();

        $agentRole = Role::where('name', 'agent')->firstOrFail();
        $membership = WorkspaceUser::where('user_id', $userWithoutRoles->id)
            ->where('workspace_id', $initialWorkspaceId)
            ->first();

        $this->assertNotNull($membership);
        $this->assertSame($agentRole->id, $membership->role_id);
        $this->assertFalse($membership->is_active);
    }

    /** 7. Backfill y aislamiento de presencia en agent_presence_sessions */
    public function test_07_agent_presence_sessions_backfill_and_indexing(): void
    {
        $initialWorkspaceId = Workspace::initialWorkspaceId();
        $user = User::factory()->create();

        $session = AgentPresenceSession::create([
            'user_id' => $user->id,
            'session_key' => hash('sha256', 'laravel_session_123'),
            'started_at' => now()->subHour(),
            'last_heartbeat_at' => now(),
        ]);

        $this->assertSame($initialWorkspaceId, $session->workspace_id);

        // Crear una sesión en un workspace diferente
        $workspaceB = Workspace::create(['name' => 'Workspace B', 'slug' => 'workspace-b']);
        $sessionB = AgentPresenceSession::create([
            'workspace_id' => $workspaceB->id,
            'user_id' => $user->id,
            'session_key' => hash('sha256', 'laravel_session_456'),
            'started_at' => now()->subMinutes(30),
            'last_heartbeat_at' => now(),
        ]);

        $this->assertSame($workspaceB->id, $sessionB->workspace_id);

        // Consulta filtrada por workspace debe aislar las sesiones
        $initialSessions = AgentPresenceSession::where('workspace_id', $initialWorkspaceId)->pluck('id');
        $this->assertTrue($initialSessions->contains($session->id));
        $this->assertFalse($initialSessions->contains($sessionB->id));
    }

    /** 8. Audit logs: discriminación entre recursos de negocio y plataforma */
    public function test_08_audit_logs_platform_vs_business_isolation(): void
    {
        $initialWorkspaceId = Workspace::initialWorkspaceId();
        $flow = Flow::create(['name' => 'Audit Test Flow']);

        // Log de negocio
        $businessLog = AuditLog::create([
            'action' => 'flow.created',
            'entity_type' => 'Flow',
            'entity_id' => $flow->id,
            'created_at' => now(),
            'workspace_id' => $initialWorkspaceId,
        ]);

        // Log de infraestructura / plataforma
        $platformLog = AuditLog::create([
            'action' => 'auth.init_admin',
            'entity_type' => 'SystemSetting',
            'entity_id' => null,
            'created_at' => now(),
            'workspace_id' => null,
        ]);

        $this->assertSame($initialWorkspaceId, $businessLog->workspace_id);
        $this->assertNull($platformLog->workspace_id);

        $tenantLogs = AuditLog::where('workspace_id', $initialWorkspaceId)->pluck('id');
        $this->assertTrue($tenantLogs->contains($businessLog->id));
        $this->assertFalse($tenantLogs->contains($platformLog->id));
    }

    /** 9. Unicidades compuestas por workspace (teams, tags, canned_responses) */
    public function test_09_composite_uniqueness_for_teams_tags_and_canned_responses(): void
    {
        $wsA = Workspace::create(['name' => 'Company A', 'slug' => 'company-a']);
        $wsB = Workspace::create(['name' => 'Company B', 'slug' => 'company-b']);

        // Equipos con el mismo nombre en diferentes workspaces deben permitirse
        $teamA = Team::create(['workspace_id' => $wsA->id, 'name' => 'Soporte']);
        $teamB = Team::create(['workspace_id' => $wsB->id, 'name' => 'Soporte']);
        $this->assertNotNull($teamA->id);
        $this->assertNotNull($teamB->id);

        // Duplicar equipo dentro del mismo workspace debe fallar
        try {
            Team::create(['workspace_id' => $wsA->id, 'name' => 'Soporte']);
            $this->fail('Se esperaba fallo por unicidad compuesta de equipo.');
        } catch (QueryException $e) {
            $this->assertTrue(true);
        }

        // Tags con mismo slug en diferentes workspaces
        $tagA = Tag::create(['workspace_id' => $wsA->id, 'name' => 'Urgente', 'slug' => 'urgente']);
        $tagB = Tag::create(['workspace_id' => $wsB->id, 'name' => 'Urgente', 'slug' => 'urgente']);
        $this->assertNotNull($tagA->id);
        $this->assertNotNull($tagB->id);

        // Duplicar tag en mismo workspace debe fallar
        try {
            Tag::create(['workspace_id' => $wsA->id, 'name' => 'Urgente', 'slug' => 'urgente']);
            $this->fail('Se esperaba fallo por unicidad compuesta de tag.');
        } catch (QueryException $e) {
            $this->assertTrue(true);
        }

        // Canned responses con mismo shortcut en diferentes workspaces
        $cannedA = CannedResponse::create([
            'workspace_id' => $wsA->id,
            'title' => 'Saludo A',
            'shortcut' => 'hola',
            'content' => 'Hola desde A',
        ]);
        $cannedB = CannedResponse::create([
            'workspace_id' => $wsB->id,
            'title' => 'Saludo B',
            'shortcut' => 'hola',
            'content' => 'Hola desde B',
        ]);
        $this->assertNotNull($cannedA->id);
        $this->assertNotNull($cannedB->id);

        // Duplicar shortcut en el mismo workspace debe fallar
        try {
            CannedResponse::create([
                'workspace_id' => $wsA->id,
                'title' => 'Otro Saludo',
                'shortcut' => 'hola',
                'content' => 'Duplicado',
            ]);
            $this->fail('Se esperaba fallo por unicidad compuesta de canned response.');
        } catch (QueryException $e) {
            $this->assertTrue(true);
        }
    }

    /** 10. Rollback en base vacía */
    public function test_10_rollback_on_empty_database(): void
    {
        // En base vacía, el rollback de las migraciones multitenant debe completarse sin excepciones
        DB::table('workspaces')->delete();
        $exitCode = $this->rollbackThroughMultitenancyMigration(
            '2026_11_20_000001_create_workspaces_and_workspace_user_tables'
        );
        $this->assertSame(0, $exitCode);

        // Restaurar estado para siguientes pruebas
        RefreshDatabaseState::$migrated = false;
        Artisan::call('migrate', ['--force' => true]);
    }

    /** 11. Rollback en escenario single-tenant */
    public function test_11_rollback_in_single_tenant_scenario(): void
    {
        // Con exactamente 1 workspace (single-tenant), el rollback es permitido limpiamente
        $this->assertSame(1, DB::table('workspaces')->count());

        $exitCode = $this->rollbackThroughMultitenancyMigration(
            '2026_11_20_000001_create_workspaces_and_workspace_user_tables'
        );
        $this->assertSame(0, $exitCode);

        $this->assertFalse(Schema::hasTable('workspaces'));
        $this->assertFalse(Schema::hasTable('workspace_user'));

        // Restaurar estado
        RefreshDatabaseState::$migrated = false;
        Artisan::call('migrate', ['--force' => true]);
    }

    /** 12. Bloqueo seguro de rollback cuando existen múltiples workspaces */
    public function test_12_rollback_is_blocked_safely_when_multiple_workspaces_exist(): void
    {
        Workspace::create(['name' => 'Secondary Tenant', 'slug' => 'secondary']);
        $this->assertGreaterThan(1, DB::table('workspaces')->count());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ABORTADO: Existen múltiples workspaces con datos activos');

        $this->rollbackThroughMultitenancyMigration(
            '2026_11_20_000001_create_workspaces_and_workspace_user_tables'
        );
    }

    /** 13. Verificación de ejecución de migraciones en SQLite */
    public function test_13_sqlite_migration_execution_and_schema_verification(): void
    {
        $this->assertSame('sqlite', DB::getDriverName());

        $this->assertTrue(Schema::hasTable('workspaces'));
        $this->assertTrue(Schema::hasTable('workspace_user'));
        $this->assertTrue(Schema::hasColumn('users', 'active_workspace_id'));
        $this->assertTrue(Schema::hasColumn('users', 'is_platform_admin'));

        foreach ([
            'channels', 'contacts', 'flows', 'conversations', 'teams',
            'tags', 'canned_responses', 'saved_funnels', 'audit_logs', 'agent_presence_sessions',
        ] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'workspace_id'));
        }

        // Messages NO debe tener workspace_id directo
        $this->assertFalse(Schema::hasColumn('messages', 'workspace_id'));
    }

    /** 15. Verificación de que workspace_id no tiene default en las 9 tablas directas y falla al omitirse */
    public function test_15_workspace_id_columns_have_no_defaults_and_fail_on_missing_insert(): void
    {
        $tenantTables = [
            'channels' => [
                'id' => (string) Str::uuid(),
                'name' => 'Canal Sin Workspace',
                'type' => 'webchat',
            ],
            'contacts' => [
                'id' => (string) Str::uuid(),
                'display_name' => 'Contacto Sin Workspace',
            ],
            'flows' => [
                'id' => (string) Str::uuid(),
                'name' => 'Flujo Sin Workspace',
                'status' => 'draft',
            ],
            'conversations' => [
                'id' => (string) Str::uuid(),
                'channel_id' => (string) Str::uuid(),
                'contact_id' => (string) Str::uuid(),
                'status' => 'active',
            ],
            'teams' => [
                'id' => (string) Str::uuid(),
                'name' => 'Equipo Sin Workspace',
            ],
            'tags' => [
                'id' => (string) Str::uuid(),
                'name' => 'Tag Sin Workspace',
                'slug' => 'tag-sin-ws',
            ],
            'canned_responses' => [
                'id' => (string) Str::uuid(),
                'title' => 'Respuesta Sin Workspace',
                'shortcut' => 'resp_sin_ws',
                'content' => 'Texto',
            ],
            'saved_funnels' => [
                'id' => (string) Str::uuid(),
                'flow_id' => (string) Str::uuid(),
                'name' => 'Funnel Sin Workspace',
                'steps' => json_encode([]),
            ],
            'agent_presence_sessions' => [
                'user_id' => 1,
                'session_key' => str_repeat('a', 64),
                'started_at' => now(),
                'last_heartbeat_at' => now(),
            ],
        ];

        foreach ($tenantTables as $tableName => $payload) {
            // Verificar ausencia de dflt_value en SQLite
            if (DB::getDriverName() === 'sqlite') {
                $columns = collect(DB::select("PRAGMA table_info('{$tableName}')"));
                $wsCol = $columns->firstWhere('name', 'workspace_id');

                $this->assertNotNull($wsCol, "Columna workspace_id no encontrada en tabla {$tableName}");
                $this->assertSame(1, (int) $wsCol->notnull, "La columna workspace_id en {$tableName} debe ser NOT NULL");
                $this->assertNull($wsCol->dflt_value, "La columna workspace_id en {$tableName} NO debe tener valor predeterminado (dflt_value)");
            }

            // Inserción directa sin workspace_id debe fallar por NOT NULL constraint
            $failedAsExpected = false;
            try {
                DB::table($tableName)->insert($payload);
            } catch (QueryException $e) {
                $failedAsExpected = true;
                $this->assertStringContainsStringIgnoringCase('NOT NULL', $e->getMessage());
            }

            $this->assertTrue($failedAsExpected, "La inserción en {$tableName} sin workspace_id debió fallar por NOT NULL constraint");
        }
    }

    /** 16. Verificación de jerarquía de selección del propietario inicial */
    public function test_16_initial_owner_selection_priority_by_role_hierarchy(): void
    {
        DB::table('workspaces')->delete();
        DB::table('role_user')->delete();
        DB::table('users')->delete();

        $agentUser = User::factory()->create(['id' => 10, 'name' => 'Agent User']);
        $supervisorUser = User::factory()->create(['id' => 20, 'name' => 'Supervisor User']);
        $adminUser = User::factory()->create(['id' => 30, 'name' => 'Admin User']);
        $superAdminUser = User::factory()->create(['id' => 40, 'name' => 'Super Admin User']);

        $agentRole = Role::where('name', 'agent')->firstOrFail();
        $supervisorRole = Role::where('name', 'supervisor')->firstOrFail();
        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']);
        $superAdminRole = Role::where('name', 'super_admin')->firstOrFail();

        $agentUser->roles()->attach($agentRole);
        $supervisorUser->roles()->attach($supervisorRole);
        $adminUser->roles()->attach($adminRole);
        $superAdminUser->roles()->attach($superAdminRole);

        // Escenario A: super_admin (ID 40) debe ganar sobre usuarios con menor ID pero menor jerarquía
        $this->rollbackThroughMultitenancyMigration('2026_11_20_000003_backfill_initial_workspace_data');
        Artisan::call('migrate', ['--force' => true]);

        $workspace = DB::table('workspaces')->where('slug', 'principal')->first();
        $this->assertNotNull($workspace);
        $this->assertSame($superAdminUser->id, (int) $workspace->initial_owner_id);

        // Escenario B: sin super_admin, debe ganar admin (ID 30)
        DB::table('workspaces')->delete();
        $superAdminUser->roles()->detach();

        $this->rollbackThroughMultitenancyMigration('2026_11_20_000003_backfill_initial_workspace_data');
        Artisan::call('migrate', ['--force' => true]);

        $workspace = DB::table('workspaces')->where('slug', 'principal')->first();
        $this->assertSame($adminUser->id, (int) $workspace->initial_owner_id);

        // Escenario C: sin admin ni super_admin, gana supervisor (ID 20)
        DB::table('workspaces')->delete();
        $adminUser->roles()->detach();

        $this->rollbackThroughMultitenancyMigration('2026_11_20_000003_backfill_initial_workspace_data');
        Artisan::call('migrate', ['--force' => true]);

        $workspace = DB::table('workspaces')->where('slug', 'principal')->first();
        $this->assertSame($supervisorUser->id, (int) $workspace->initial_owner_id);

        // Escenario D: sin roles en ningún usuario, fallback al primer usuario existente por ID (ID 10)
        DB::table('workspaces')->delete();
        $supervisorUser->roles()->detach();
        $agentUser->roles()->detach();

        $this->rollbackThroughMultitenancyMigration('2026_11_20_000003_backfill_initial_workspace_data');
        Artisan::call('migrate', ['--force' => true]);

        $workspace = DB::table('workspaces')->where('slug', 'principal')->first();
        $this->assertSame($agentUser->id, (int) $workspace->initial_owner_id);

        RefreshDatabaseState::$migrated = false;
        Artisan::call('migrate', ['--force' => true]);
    }

    /** 14. Verificación de entorno PostgreSQL (si está disponible o reporte de estado) */
    public function test_14_postgresql_migration_environment_check(): void
    {
        $driver = config('database.default');

        if ($driver === 'pgsql') {
            $this->assertSame('pgsql', DB::getDriverName());
            $this->assertTrue(Schema::hasTable('workspaces'));
        } else {
            // Entorno local Mac sin contenedor Docker / PostgreSQL activo
            $this->assertContains($driver, ['sqlite', 'testing']);
            $this->assertTrue(Schema::hasTable('workspaces'));
        }
    }
}
