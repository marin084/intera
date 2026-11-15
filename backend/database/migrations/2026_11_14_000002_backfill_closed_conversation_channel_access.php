<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $assignedRoleUsers = DB::table('role_user')
            ->join('permission_role', 'permission_role.role_id', '=', 'role_user.role_id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('permissions.name', 'conversations.view_assigned')
            ->distinct()
            ->pluck('role_user.user_id')
            ->flip();

        $now = now();
        $rows = [];
        DB::table('conversations')
            ->where('status', 'closed')
            ->whereNotNull('metadata->last_assigned_user_id')
            ->select(['channel_id', 'metadata'])
            ->orderBy('id')
            ->chunk(500, function ($conversations) use (&$rows, $assignedRoleUsers, $now) {
                foreach ($conversations as $conversation) {
                    $metadata = is_array($conversation->metadata)
                        ? $conversation->metadata
                        : json_decode((string) $conversation->metadata, true);
                    $userId = isset($metadata['last_assigned_user_id']) ? (int) $metadata['last_assigned_user_id'] : null;
                    if (! $userId || ! $assignedRoleUsers->has($userId)) {
                        continue;
                    }
                    $rows[] = [
                        'user_id' => $userId,
                        'channel_id' => $conversation->channel_id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if (count($rows) >= 500) {
                    DB::table('channel_user')->insertOrIgnore($rows);
                    $rows = [];
                }
            });

        if ($rows) {
            DB::table('channel_user')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        // Access grants are user-managed state and are not safe to reverse automatically.
    }
};
