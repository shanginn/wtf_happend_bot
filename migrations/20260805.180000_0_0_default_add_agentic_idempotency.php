<?php

declare(strict_types=1);

namespace Migration;

use Cycle\Migrations\Migration;

final class OrmDefaultAddAgenticIdempotency20260805180000 extends Migration
{
    protected const DATABASE = 'default';

    public function up(): void
    {
        $this->table('update_records')
            ->addColumn('ingestion_run_id', 'text', ['nullable' => true])
            ->addIndex(['ingestion_run_id'], [
                'name' => 'update_records_index_ingestion_run_id',
                'unique' => false,
            ])
            ->update();

        $this->table('tool_execution_records')
            ->addColumn('id', 'primary', ['nullable' => false])
            ->addColumn('idempotency_key', 'text', ['nullable' => false])
            ->addColumn('tool_name', 'text', ['nullable' => false])
            ->addColumn('result_json', 'text', ['nullable' => true])
            ->addColumn('created_at', 'bigInteger', ['nullable' => false])
            ->addColumn('completed_at', 'bigInteger', ['nullable' => true])
            ->addIndex(['idempotency_key'], [
                'name' => 'tool_execution_records_index_idempotency_key',
                'unique' => true,
            ])
            ->addIndex(['completed_at'], [
                'name' => 'tool_execution_records_index_completed_at',
                'unique' => false,
            ])
            ->setPrimaryKeys(['id'])
            ->create();

        $this->table('model_completion_records')
            ->addColumn('id', 'primary', ['nullable' => false])
            ->addColumn('idempotency_key', 'text', ['nullable' => false])
            ->addColumn('result_json', 'text', ['nullable' => false])
            ->addColumn('created_at', 'bigInteger', ['nullable' => false])
            ->addIndex(['idempotency_key'], [
                'name' => 'model_completion_records_index_idempotency_key',
                'unique' => true,
            ])
            ->addIndex(['created_at'], [
                'name' => 'model_completion_records_index_created_at',
                'unique' => false,
            ])
            ->setPrimaryKeys(['id'])
            ->create();
    }

    public function down(): void
    {
        $this->table('model_completion_records')->drop();
        $this->table('tool_execution_records')->drop();
        $this->table('update_records')
            ->dropIndex(['ingestion_run_id'])
            ->dropColumn('ingestion_run_id')
            ->update();
    }
}
