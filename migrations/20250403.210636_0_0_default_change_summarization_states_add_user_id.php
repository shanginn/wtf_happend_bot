<?php

declare(strict_types=1);

namespace Migration;

use Cycle\Migrations\Migration;

class OrmDefaultChangeAddUserIdToSummarizationStates extends Migration
{
    protected const DATABASE = 'default';

    public function up(): void
    {
        $this->table('summarization_states')
            ->addColumn('user_id', 'bigInteger', ['nullable' => false, 'defaultValue' => 0])
            ->update();
        
        // Add a unique index on chat_id and user_id
        $this->table('summarization_states')
            ->addIndex(['chat_id', 'user_id'], ['unique' => true])
            ->update();
    }

    public function down(): void
    {
        $this->table('summarization_states')
            ->dropIndex(['chat_id', 'user_id'])
            ->dropColumn('user_id')
            ->update();
    }
}