<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Rename super_admin display_name to 'Administrador' and set capacity to 0
        DB::table('roles')->where('name', 'super_admin')->update([
            'display_name' => 'Administrador',
            'max_concurrent_conversations' => 0,
        ]);

        // 2. Set flow_manager capacity to 0 (no direct auto-assignment)
        DB::table('roles')->where('name', 'flow_manager')->update([
            'max_concurrent_conversations' => 0,
        ]);

        // 3. Ensure supervisor and agent have default 5
        DB::table('roles')->where('name', 'supervisor')->where(function ($q) {
            $q->whereNull('max_concurrent_conversations')->orWhere('max_concurrent_conversations', 0);
        })->update([
            'max_concurrent_conversations' => 5,
        ]);

        DB::table('roles')->where('name', 'agent')->where(function ($q) {
            $q->whereNull('max_concurrent_conversations')->orWhere('max_concurrent_conversations', 0);
        })->update([
            'max_concurrent_conversations' => 5,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('roles')->where('name', 'super_admin')->update([
            'display_name' => 'Super Administrador',
            'max_concurrent_conversations' => null,
        ]);

        DB::table('roles')->where('name', 'flow_manager')->update([
            'max_concurrent_conversations' => null,
        ]);
    }
};
