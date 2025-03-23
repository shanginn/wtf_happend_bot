<?php

declare(strict_types=1);

namespace Migration;

use Cycle\Migrations\Migration;

class OrmDefault3bb3a6f626cd4d728b765fb7a7a5ac8f extends Migration
{
    protected const DATABASE = 'default';

    public function up(): void
    {
        $this->table('summarization_states')
        ->addColumn('id', 'primary', ['nullable' => false, 'defaultValue' => null])
        ->addColumn('chat_id', 'bigInteger', ['nullable' => false, 'defaultValue' => null])
        ->addColumn('last_summarized_message_id', 'bigInteger', ['nullable' => false, 'defaultValue' => null])
        ->setPrimaryKeys(['id'])
        ->create();
    }

    public function down(): void
    {
        $this->table('summarization_states')->drop();
    }
}
