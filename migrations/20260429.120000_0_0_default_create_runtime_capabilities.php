<?php

declare(strict_types=1);

namespace Migration;

use Cycle\Migrations\Migration;

class OrmDefaultCreateRuntimeCapabilities20260429120000 extends Migration
{
    protected const DATABASE = 'default';

    public function up(): void
    {
        $this->table('runtime_skills')
            ->addColumn('id', 'primary', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
            ->addColumn('chat_id', 'bigInteger', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
            ->addColumn('name', 'text', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
            ->addColumn('description', 'text', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
            ->addColumn('body', 'text', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
            ->addColumn('enabled', 'boolean', ['nullable' => false, 'defaultValue' => true, 'comment' => ''])
            ->addColumn('created_at', 'bigInteger', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
            ->addColumn('updated_at', 'bigInteger', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
            ->addIndex(['chat_id', 'name'], [
                'name' => 'runtime_skills_index_chat_id_name',
                'unique' => true,
            ])
            ->addIndex(['chat_id', 'enabled'], [
                'name' => 'runtime_skills_index_chat_id_enabled',
                'unique' => false,
            ])
            ->setPrimaryKeys(['id'])
            ->create();

        $this->table('runtime_tools')
            ->addColumn('id', 'primary', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
            ->addColumn('chat_id', 'bigInteger', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
            ->addColumn('name', 'text', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
            ->addColumn('description', 'text', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
            ->addColumn('parameters_schema', 'text', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
            ->addColumn('instructions', 'text', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
            ->addColumn('enabled', 'boolean', ['nullable' => false, 'defaultValue' => true, 'comment' => ''])
            ->addColumn('created_at', 'bigInteger', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
            ->addColumn('updated_at', 'bigInteger', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
            ->addIndex(['chat_id', 'name'], [
                'name' => 'runtime_tools_index_chat_id_name',
                'unique' => true,
            ])
            ->addIndex(['chat_id', 'enabled'], [
                'name' => 'runtime_tools_index_chat_id_enabled',
                'unique' => false,
            ])
            ->setPrimaryKeys(['id'])
            ->create();
    }

    public function down(): void
    {
        $this->table('runtime_tools')->drop();
        $this->table('runtime_skills')->drop();
    }
}
