<?php

declare(strict_types=1);

namespace Migration;

use Cycle\Migrations\Migration;

class OrmDefault694408d3c78b6de3dc2cebf2dc04e20b extends Migration
{
    protected const DATABASE = 'default';

    public function up(): void
    {
        $this->table('update_records')
        ->addColumn('update_id', 'bigInteger', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
        ->addColumn('update', 'text', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
        ->addColumn('chat_id', 'bigInteger', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
        ->addColumn('topic_id', 'bigInteger', ['nullable' => true, 'defaultValue' => null, 'comment' => ''])
        ->addIndex(['chat_id'], ['name' => 'update_records_index_chat_id_69a06e7ca6161', 'unique' => false])
        ->setPrimaryKeys(['update_id'])
        ->create();
    }

    public function down(): void
    {
        $this->table('update_records')->drop();
    }
}
