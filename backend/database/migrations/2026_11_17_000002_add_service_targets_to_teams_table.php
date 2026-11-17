<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->unsignedInteger('queue_wait_target_seconds')->nullable()->after('description');
            $table->unsignedInteger('first_response_target_seconds')->nullable()->after('queue_wait_target_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['queue_wait_target_seconds', 'first_response_target_seconds']);
        });
    }
};
