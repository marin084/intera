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
        if (Schema::hasTable('flow_versions')) {
            Schema::table('flow_versions', function (Blueprint $table) {
                if (! Schema::hasColumn('flow_versions', 'metadata')) {
                    $table->json('metadata')->nullable()->after('description');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('flow_versions')) {
            Schema::table('flow_versions', function (Blueprint $table) {
                if (Schema::hasColumn('flow_versions', 'metadata')) {
                    $table->dropColumn('metadata');
                }
            });
        }
    }
};
