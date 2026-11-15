<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_presence_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Hash of the authenticated Laravel session ID; never persist the raw ID.
            $table->char('session_key', 64);
            $table->timestamp('started_at');
            $table->timestamp('last_heartbeat_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'started_at', 'ended_at'], 'agent_presence_user_period_idx');
            $table->index(['user_id', 'session_key', 'ended_at'], 'agent_presence_session_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_presence_sessions');
    }
};
