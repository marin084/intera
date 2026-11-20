<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webchat_channel_settings', function (Blueprint $table): void {
            $table->string('launcher_icon_path', 500)->nullable()->after('launcher_description');
        });
    }

    public function down(): void
    {
        Schema::table('webchat_channel_settings', function (Blueprint $table): void {
            $table->dropColumn('launcher_icon_path');
        });
    }
};
