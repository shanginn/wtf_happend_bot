<?php

declare(strict_types=1);

namespace Migration;

use Cycle\Migrations\Migration;

class OrmDefault546939f3e3662ed246685a2515801209 extends Migration
{
    protected const DATABASE = 'default';

    public function up(): void
    {
        $this->table('messages')
        ->addColumn('id', 'primary', ['nullable' => false, 'defaultValue' => null])
        ->addColumn('text', 'text', ['nullable' => false, 'defaultValue' => null])
        ->addColumn('chat_id', 'bigInteger', ['nullable' => false, 'defaultValue' => null])
        ->addColumn('date', 'bigInteger', ['nullable' => false, 'defaultValue' => null])
        ->addColumn('from_user_id', 'bigInteger', ['nullable' => false, 'defaultValue' => null])
        ->addColumn('from_username', 'text', ['nullable' => true, 'defaultValue' => null])
        ->setPrimaryKeys(['id'])
        ->create();
    }

    public function down(): void
    {
        $this->table('messages')->drop();
    }
}
