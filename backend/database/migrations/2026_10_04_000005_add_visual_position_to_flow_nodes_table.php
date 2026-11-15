<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('flow_nodes')) {
            Schema::table('flow_nodes', function (Blueprint $table) {
                if (! Schema::hasColumn('flow_nodes', 'position_x')) {
                    $table->double('position_x')->nullable()->after('configuration');
                }
                if (! Schema::hasColumn('flow_nodes', 'position_y')) {
                    $table->double('position_y')->nullable()->after('position_x');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('flow_nodes')) {
            Schema::table('flow_nodes', function (Blueprint $table) {
                if (Schema::hasColumn('flow_nodes', 'position_y')) {
                    $table->dropColumn('position_y');
                }
                if (Schema::hasColumn('flow_nodes', 'position_x')) {
                    $table->dropColumn('position_x');
                }
            });
        }
    }
};
