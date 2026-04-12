<?php

declare(strict_types=1);

namespace Migration;

use Cycle\Migrations\Migration;

class OrmDefault2864bfdcf2ca60d8d7f6432e2f9082d6 extends Migration
{
    protected const DATABASE = 'default';

    public function up(): void
    {
        $this->table('update_records')
        ->addColumn('created_at', 'bigInteger', ['nullable' => false, 'defaultValue' => 0])
        ->update();
    }

    public function down(): void
    {
        $this->table('update_records')
        ->dropColumn('created_at')
        ->update();
    }
}
