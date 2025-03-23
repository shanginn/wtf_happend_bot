<?php

declare(strict_types=1);

namespace Migration;

use Cycle\Migrations\Migration;

class OrmDefault0647d8d12a6eaf167052ad1e98b6b9a6 extends Migration
{
    protected const DATABASE = 'default';

    public function up(): void
    {
        $this->table('messages')
        ->addColumn('message_id', 'bigInteger', ['nullable' => false, 'defaultValue' => null])
        ->update();
    }

    public function down(): void
    {
        $this->table('messages')
        ->dropColumn('message_id')
        ->update();
    }
}
