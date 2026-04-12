<?php

declare(strict_types=1);

namespace Migration;

use Cycle\Migrations\Migration;

class OrmDefault08580028a7a5dbe7399ac532456f85d0 extends Migration
{
    protected const DATABASE = 'default';

    public function up(): void
    {
        // Intentionally left blank.
        // Participant memories were simplified before deployment, and the table is
        // now recreated by later migrations in its final shape.
    }

    public function down(): void
    {
        // Intentionally left blank.
    }
}
