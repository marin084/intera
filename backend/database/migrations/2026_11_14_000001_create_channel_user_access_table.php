<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('channel_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'channel_id']);
        });

        // Preserve current access for existing users before switching to explicit grants.
        $globalUserIds = DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->leftJoin('permission_role', 'permission_role.role_id', '=', 'roles.id')
            ->leftJoin('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where(function ($query) {
                $query->whereIn('roles.name', ['admin', 'super_admin'])
                    ->orWhereIn('permissions.name', ['conversations.view_all', 'channels.view', 'channels.manage']);
            })
            ->distinct()
            ->pluck('role_user.user_id');

        $now = now();
        foreach ($globalUserIds as $userId) {
            $rows = DB::table('channels')->pluck('id')->map(fn ($channelId) => [
                'user_id' => $userId,
                'channel_id' => $channelId,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('channel_user')->insertOrIgnore($chunk);
            }
        }

        $assignedUsers = DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->join('permission_role', 'permission_role.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('permissions.name', 'conversations.view_assigned')
            ->whereNotIn('role_user.user_id', $globalUserIds)
            ->distinct()
            ->pluck('role_user.user_id');

        foreach ($assignedUsers as $userId) {
            $rows = DB::table('conversations')
                ->where('assigned_user_id', $userId)
                ->distinct()
                ->pluck('channel_id')
                ->map(fn ($channelId) => [
                    'user_id' => $userId,
                    'channel_id' => $channelId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('channel_user')->insertOrIgnore($chunk);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_user');
    }
};
