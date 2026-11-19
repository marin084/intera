<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('slug', 80)->unique();
            $table->string('status', 30)->default('active')->index('idx_workspaces_status');
            $table->foreignId('initial_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('configuration')->nullable();
            $table->timestamps();
        });

        Schema::create('workspace_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id'], 'uniq_workspace_user');
            $table->index(['user_id', 'is_active'], 'idx_workspace_user_user');
            $table->index(['workspace_id', 'is_active'], 'idx_workspace_user_workspace');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignUuid('active_workspace_id')
                ->nullable()
                ->constrained('workspaces')
                ->nullOnDelete();
            $table->boolean('is_platform_admin')->default(false);
        });
    }

    protected function withoutForeignKeys(callable $callback): void
    {
        Schema::disableForeignKeyConstraints();

        try {
            $callback();
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('workspaces') && DB::table('workspaces')->count() > 1) {
            throw new RuntimeException(
                'ABORTADO: Existen múltiples workspaces con datos activos. '.
                'Un rollback directo mezclaría o destruiría irrevocablemente los datos de diferentes empresas. '.
                'Ejecute un backup completo y consolide o archive los workspaces secundarios antes de revertir.'
            );
        }

        $this->withoutForeignKeys(function (): void {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropForeign(['active_workspace_id']);
                $table->dropColumn(['active_workspace_id', 'is_platform_admin']);
            });

            Schema::dropIfExists('workspace_user');
            Schema::dropIfExists('workspaces');
        });
    }
};
