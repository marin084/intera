<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            // User Management
            ['name' => 'users.view', 'display_name' => 'Ver usuarios', 'module' => 'users'],
            ['name' => 'users.manage', 'display_name' => 'Administrar usuarios', 'module' => 'users'],

            // Roles & Permissions
            ['name' => 'roles.view', 'display_name' => 'Ver roles', 'module' => 'roles'],
            ['name' => 'roles.manage', 'display_name' => 'Administrar roles y permisos', 'module' => 'roles'],

            // Channels
            ['name' => 'channels.view', 'display_name' => 'Ver canales', 'module' => 'channels'],
            ['name' => 'channels.manage', 'display_name' => 'Administrar canales', 'module' => 'channels'],

            // Flows
            ['name' => 'flows.view', 'display_name' => 'Ver flujos', 'module' => 'flows'],
            ['name' => 'flows.create', 'display_name' => 'Crear flujos', 'module' => 'flows'],
            ['name' => 'flows.edit', 'display_name' => 'Editar flujos', 'module' => 'flows'],
            ['name' => 'flows.publish', 'display_name' => 'Publicar flujos', 'module' => 'flows'],
            ['name' => 'flows.delete', 'display_name' => 'Eliminar o archivar flujos', 'module' => 'flows'],

            // Conversations & Inbox
            ['name' => 'conversations.view_all', 'display_name' => 'Ver todas las conversaciones', 'module' => 'conversations'],
            ['name' => 'conversations.view_assigned', 'display_name' => 'Ver conversaciones asignadas', 'module' => 'conversations'],
            ['name' => 'conversations.reply', 'display_name' => 'Responder mensajes en conversaciones', 'module' => 'conversations'],
            ['name' => 'conversations.assign', 'display_name' => 'Asignar y reasignar conversaciones', 'module' => 'conversations'],
            ['name' => 'canned_responses.view', 'display_name' => 'Ver respuestas rápidas', 'module' => 'inbox'],
            ['name' => 'canned_responses.manage', 'display_name' => 'Administrar respuestas rápidas', 'module' => 'inbox'],

            // Agents Overview
            ['name' => 'agents.view', 'display_name' => 'Ver agentes operativos', 'module' => 'agents'],

            // Teams and routing queues
            ['name' => 'teams.view', 'display_name' => 'Ver equipos y colas propias', 'module' => 'teams'],
            ['name' => 'teams.manage', 'display_name' => 'Administrar equipos y rutas', 'module' => 'teams'],

            // Audit & System
            ['name' => 'audit_logs.view', 'display_name' => 'Consultar logs de auditoría', 'module' => 'audit'],
            ['name' => 'instance.configure', 'display_name' => 'Configurar parámetros de la instancia', 'module' => 'system'],
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm['name']], $perm);
        }

        // Roles
        $superAdmin = Role::updateOrCreate(
            ['name' => 'super_admin'],
            [
                'display_name' => 'Administrador',
                'description' => 'Control total del sistema, configuración, flujos, usuarios y auditoría.',
                'max_concurrent_conversations' => 0,
            ]
        );

        $flowManager = Role::updateOrCreate(
            ['name' => 'flow_manager'],
            [
                'display_name' => 'Gestor de Flujos',
                'description' => 'Administración y publicación de flujos conversacionales y consulta de canales.',
                'max_concurrent_conversations' => 0,
            ]
        );

        $supervisor = Role::updateOrCreate(
            ['name' => 'supervisor'],
            [
                'display_name' => 'Supervisor de Atención',
                'description' => 'Supervisión de todas las conversaciones, reasignación y consulta de agentes.',
                'max_concurrent_conversations' => 5,
            ]
        );

        $agent = Role::updateOrCreate(
            ['name' => 'agent'],
            [
                'display_name' => 'Agente de Atención',
                'description' => 'Atención humana directa de conversaciones asignadas en bandeja.',
                'max_concurrent_conversations' => 5,
            ]
        );

        // Assign all permissions to super_admin
        $superAdmin->permissions()->sync(Permission::all());

        // Assign flow manager permissions
        $flowManagerPermissions = Permission::whereIn('name', [
            'channels.view',
            'flows.view',
            'flows.create',
            'flows.edit',
            'flows.publish',
        ])->get();
        $flowManager->permissions()->sync($flowManagerPermissions);

        // Assign supervisor permissions
        $supervisorPermissions = Permission::whereIn('name', [
            'conversations.view_all',
            'conversations.view_assigned',
            'conversations.reply',
            'conversations.assign',
            'agents.view',
            'teams.view',
            'canned_responses.view',
            'canned_responses.manage',
        ])->get();
        $supervisor->permissions()->sync($supervisorPermissions);

        // Assign agent permissions
        $agentPermissions = Permission::whereIn('name', [
            'conversations.view_assigned',
            'conversations.reply',
            'canned_responses.view',
            'teams.view',
        ])->get();
        $agent->permissions()->sync($agentPermissions);
    }
}
