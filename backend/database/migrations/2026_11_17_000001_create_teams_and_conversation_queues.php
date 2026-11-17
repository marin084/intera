<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('team_user', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['team_id', 'user_id']);
        });

        Schema::create('team_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignUuid('channel_id')->constrained('channels')->cascadeOnDelete()->unique();
            $table->timestamps();
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignUuid('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->timestamp('queued_at')->nullable()->index();
            $table->index(['team_id', 'status', 'queued_at'], 'conversations_team_queue_idx');
        });

        Schema::create('conversation_queue_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained('teams')->restrictOnDelete();
            $table->timestamp('entered_at')->index();
            $table->timestamp('assigned_at')->nullable()->index();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ended_at')->nullable()->index();
            $table->string('exit_reason', 32)->nullable();
            $table->timestamps();
            $table->index(['team_id', 'ended_at', 'entered_at'], 'queue_entries_waiting_idx');
        });

        $generalTeamId = (string) Str::uuid();
        DB::table('teams')->insert([
            'id' => $generalTeamId,
            'name' => 'Equipo General',
            'description' => 'Equipo inicial para mantener la atención existente.',
            'is_default' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $eligibleUsers = DB::table('users')
            ->join('role_user', 'users.id', '=', 'role_user.user_id')
            ->join('roles', 'role_user.role_id', '=', 'roles.id')
            ->whereIn('roles.name', ['agent', 'admin', 'supervisor', 'super_admin'])
            ->distinct()
            ->pluck('users.id');

        foreach ($eligibleUsers as $userId) {
            DB::table('team_user')->insert([
                'team_id' => $generalTeamId,
                'user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('conversations')->update(['team_id' => $generalTeamId]);

        $waiting = DB::table('conversations')->where('status', 'waiting_agent')->whereNull('assigned_user_id')->get(['id']);
        foreach ($waiting as $conversation) {
            $enteredAt = now();
            DB::table('conversations')->where('id', $conversation->id)->update(['queued_at' => $enteredAt]);
            DB::table('conversation_queue_entries')->insert([
                'conversation_id' => $conversation->id,
                'team_id' => $generalTeamId,
                'entered_at' => $enteredAt,
                'created_at' => $enteredAt,
                'updated_at' => $enteredAt,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_queue_entries');
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_team_queue_idx');
            $table->dropIndex('conversations_queued_at_index');
            $table->dropForeign(['team_id']);
            $table->dropColumn(['team_id', 'queued_at']);
        });
        Schema::dropIfExists('team_channels');
        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');
    }
};
