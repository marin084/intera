<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    protected array $tables = [
        'channels',
        'contacts',
        'flows',
        'conversations',
        'teams',
        'tags',
        'canned_responses',
        'saved_funnels',
        'audit_logs',
        'agent_presence_sessions',
    ];

    protected function withoutForeignKeys(callable $callback): void
    {
        $connection = DB::connection();
        $isSqlite = $connection->getDriverName() === 'sqlite';
        $transactionLevel = 0;

        if ($isSqlite && $connection->transactionLevel() > 0) {
            $transactionLevel = $connection->transactionLevel();
            for ($i = 0; $i < $transactionLevel; $i++) {
                $connection->commit();
            }
        }

        Schema::disableForeignKeyConstraints();

        try {
            $callback();
        } finally {
            Schema::enableForeignKeyConstraints();

            if ($isSqlite && $transactionLevel > 0) {
                for ($i = 0; $i < $transactionLevel; $i++) {
                    $connection->beginTransaction();
                }
            }
        }
    }

    public function up(): void
    {
        $this->withoutForeignKeys(function (): void {
            foreach ($this->tables as $tableName) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                    if (! Schema::hasColumn($tableName, 'workspace_id')) {
                        $table->uuid('workspace_id')->nullable()->after('id');
                    }
                });
            }
        });
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
            foreach ($this->tables as $tableName) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                    if (Schema::hasColumn($tableName, 'workspace_id')) {
                        $table->dropColumn('workspace_id');
                    }
                });
            }
        });
    }
};
