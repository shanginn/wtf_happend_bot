<?php

declare(strict_types=1);

namespace Migration;

use Cycle\Migrations\Migration;

class OrmDefaultAe9c7af76414c7186326e3fcc68f7f9f extends Migration
{
    protected const DATABASE = 'default';

    public function up(): void
    {
        $this->table('user_memories')
        ->addColumn('id', 'primary', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
        ->addColumn('chat_id', 'bigInteger', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
        ->addColumn('user_identifier', 'text', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
        ->addColumn('category', 'text', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
        ->addColumn('content', 'text', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
        ->addColumn('created_at', 'bigInteger', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
        ->addColumn('updated_at', 'bigInteger', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
        ->addIndex(['chat_id', 'user_identifier'], [
            'name' => 'user_memories_index_chat_id_user_identifier_69b0e352f1647',
            'unique' => false,
        ])
        ->setPrimaryKeys(['id'])
        ->create();
    }

    public function down(): void
    {
        $this->table('user_memories')->drop();
    }
}
