<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A platform super administrator is global infrastructure authority, not
     * a workspace membership role. Existing memberships are normalized to the
     * workspace-level administrator role while the global role remains intact
     * on the user/role_user relationship.
     */
    public function up(): void
    {
        if (! Schema::hasTable('workspace_user') || ! Schema::hasTable('roles')) {
            return;
        }

        $superAdminRoleId = DB::table('roles')->where('name', 'super_admin')->value('id');
        $workspaceAdminRoleId = DB::table('roles')->where('name', 'admin')->value('id');

        if (! $superAdminRoleId || ! $workspaceAdminRoleId) {
            return;
        }

        DB::table('workspace_user')
            ->where('role_id', $superAdminRoleId)
            ->update([
                'role_id' => $workspaceAdminRoleId,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Deliberately irreversible: restoring platform super_admin as a
        // workspace role would reintroduce the privilege boundary this
        // migration removes.
    }
};
