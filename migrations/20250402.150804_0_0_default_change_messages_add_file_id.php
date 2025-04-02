<?php

declare(strict_types=1);

namespace Migration;

use Cycle\Migrations\Migration;

class OrmDefault88aba804d691d5eea3a9d6390880e4ef extends Migration
{
    protected const DATABASE = 'default';

    public function up(): void
    {
        $this->table('messages')
        ->addColumn('file_id', 'text', ['nullable' => true, 'defaultValue' => null])
        ->update();
    }

    public function down(): void
    {
        $this->table('messages')
        ->dropColumn('file_id')
        ->update();
    }
}
