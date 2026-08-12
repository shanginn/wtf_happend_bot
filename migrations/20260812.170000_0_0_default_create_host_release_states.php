<?php

declare(strict_types=1);

namespace Migration;

use Cycle\Migrations\Migration;

final class OrmDefaultCreateHostReleaseControl20260812170000 extends Migration
{
    protected const DATABASE = 'default';

    public function up(): void
    {
        $this->database()->execute(<<<'SQL'
            CREATE TABLE host_release_control (
                singleton boolean PRIMARY KEY DEFAULT true CHECK (singleton),
                desired_release_id text NOT NULL,
                active_release_id text NULL,
                phase text NOT NULL CHECK (phase IN ('prepared', 'authorized', 'ingress-retired', 'active', 'aborted')),
                last_aborted_release_id text NULL,
                generation bigint NOT NULL CHECK (generation > 0),
                created_at bigint NOT NULL,
                updated_at bigint NOT NULL,
                authorized_at bigint NULL,
                activated_at bigint NULL,
                reconciliation_owner text NULL,
                reconciliation_lease_until bigint NULL
            )
            SQL);
        $this->database()->execute(<<<'SQL'
            CREATE TABLE host_release_abortions (
                release_id text PRIMARY KEY,
                aborted_at bigint NOT NULL,
                generation bigint NOT NULL CHECK (generation > 0)
            )
            SQL);
    }

    public function down(): void
    {
        $this->database()->execute('DROP TABLE host_release_abortions');
        $this->database()->execute('DROP TABLE host_release_control');
    }
}
