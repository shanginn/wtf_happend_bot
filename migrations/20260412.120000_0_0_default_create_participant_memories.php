<?php

declare(strict_types=1);

namespace Migration;

use Cycle\Migrations\Migration;

class OrmDefaultCreateParticipantMemories20260412120000 extends Migration
{
    protected const DATABASE = 'default';

    public function up(): void
    {
        $this->table('participant_memories')
            ->addColumn('id', 'primary', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
            ->addColumn('chat_id', 'bigInteger', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
            ->addColumn('participant_key', 'text', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
            ->addColumn('participant_label', 'text', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
            ->addColumn('memory', 'text', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
            ->addColumn('quote', 'text', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
            ->addColumn('context', 'text', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
            ->addColumn('created_at', 'bigInteger', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
            ->addColumn('updated_at', 'bigInteger', ['nullable' => false, 'defaultValue' => null, 'comment' => ''])
            ->addIndex(['chat_id', 'participant_key'], [
                'name' => 'participant_memories_index_chat_id_participant_key',
                'unique' => false,
            ])
            ->setPrimaryKeys(['id'])
            ->create();
    }

    public function down(): void
    {
        $this->table('participant_memories')->drop();
    }
}
