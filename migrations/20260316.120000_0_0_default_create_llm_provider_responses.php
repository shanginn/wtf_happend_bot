<?php

declare(strict_types=1);

namespace Migration;

use Cycle\Migrations\Migration;

class OrmDefault63033a49f5492e377d03d541bd21993d extends Migration
{
    protected const DATABASE = 'default';

    public function up(): void
    {
        $this->table('llm_provider_responses')
        ->addColumn('id', 'primary', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
        ->addColumn('chat_id', 'bigInteger', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
        ->addColumn('topic_id', 'bigInteger', ['nullable' => true, 'defaultValue' => null, 'comment' => ''])
        ->addColumn('type', 'text', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
        ->addColumn('message_class', 'text', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
        ->addColumn('payload', 'text', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
        ->addColumn('raw_response', 'text', ['nullable' => true, 'defaultValue' => null, 'comment' => ''])
        ->addColumn('created_at', 'bigInteger', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
        ->addIndex(['chat_id', 'topic_id', 'type'], [
            'name' => 'llm_provider_responses_index_chat_id_topic_id_type_63033a49e6713',
            'unique' => false,
        ])
        ->setPrimaryKeys(['id'])
        ->create();
    }

    public function down(): void
    {
        $this->table('llm_provider_responses')->drop();
    }
}
