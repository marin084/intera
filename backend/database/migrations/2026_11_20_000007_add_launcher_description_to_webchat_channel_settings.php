<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webchat_channel_settings', function (Blueprint $table): void {
            $table->string('launcher_description', 200)->default('Estamos aquí para ayudarte.');
        });
    }

    public function down(): void
    {
        Schema::table('webchat_channel_settings', function (Blueprint $table): void {
            $table->dropColumn('launcher_description');
        });
    }
};
