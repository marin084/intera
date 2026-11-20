<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webchat_channel_settings', function (Blueprint $table): void {
            $table->string('launcher_style', 20)->default('circle');
            $table->unsignedSmallInteger('availability_threshold')->default(3);
            $table->string('availability_high_message', 200)->default('{count} agentes disponibles');
            $table->string('availability_low_message', 200)->default('Agentes disponibles');
            $table->string('availability_zero_message', 200)->default('No hay agentes disponibles en este momento');
            $table->string('launcher_cta_label', 100)->default('Chatear ahora');
        });
    }

    public function down(): void
    {
        Schema::table('webchat_channel_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'launcher_style',
                'availability_threshold',
                'availability_high_message',
                'availability_low_message',
                'availability_zero_message',
                'launcher_cta_label',
            ]);
        });
    }
};
