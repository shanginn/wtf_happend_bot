<?php

declare(strict_types=1);

namespace Tests\Migration;

require_once dirname(__DIR__, 2)
    . '/migrations/20260813.150000_0_0_default_reconcile_space_membership_states.php';

use Cycle\Database\DatabaseInterface;
use Cycle\Migrations\CapsuleInterface;
use Migration\OrmDefaultReconcileSpaceMembershipStates20260813150000;
use PHPUnit\Framework\TestCase;

final class ReconcileSpaceMembershipStatesMigrationTest extends TestCase
{
    public function testHistoricalAndLiveOrderingUsesEventTimeThenUpdateId(): void
    {
        $executedSql = $this->runUp();

        $reconcileSql = $executedSql[0];
        self::assertStringContainsString(
            'ORDER BY record.chat_id, event_at DESC, record.update_id DESC',
            $reconcileSql,
        );
        self::assertStringContainsString(
            'ON CONFLICT (bot_instance_id, platform, external_conversation_id)',
            $reconcileSql,
        );
        self::assertMatchesRegularExpression(
            '/WHERE\s+space_membership_states\.event_at\s*<\s*EXCLUDED\.event_at'
            . '\s+OR\s*\(\s*space_membership_states\.event_at\s*=\s*EXCLUDED\.event_at'
            . '\s+AND\s+space_membership_states\.last_update_id\s*<\s*EXCLUDED\.last_update_id\s*\)'
            . '\s+OR\s*\(\s*space_membership_states\.event_at\s*=\s*EXCLUDED\.event_at'
            . '\s+AND\s+space_membership_states\.last_update_id\s*=\s*EXCLUDED\.last_update_id'
            . '\s+AND\s+space_membership_states\.membership_status\s*=\s*EXCLUDED\.membership_status'
            . '\s+AND\s+space_membership_states\.active\s*=\s*EXCLUDED\.active\s*\)\s*$/s',
            $reconcileSql,
        );
        self::assertStringContainsString('updated_at = GREATEST(', $reconcileSql);
        self::assertStringNotContainsString(
            'ORDER BY record.chat_id, record.update_id DESC',
            $reconcileSql,
        );
        self::assertStringNotContainsString('last_update_id <= EXCLUDED.last_update_id', $reconcileSql);
    }

    public function testReconciledMembershipOnlyChangesRootSpace(): void
    {
        $executedSql = $this->runUp();

        $spaceUpdateSql = $executedSql[1];
        self::assertStringContainsString('UPDATE agent_spaces AS space', $spaceUpdateSql);
        self::assertStringContainsString('dream_enabled = membership.active', $spaceUpdateSql);
        self::assertStringContainsString('binding.space_id = space.id', $spaceUpdateSql);
        self::assertStringContainsString("binding.external_thread_id = ''", $spaceUpdateSql);
    }

    public function testDownPreservesB1TableAndAnyNewerLiveState(): void
    {
        $capsule = $this->createMock(CapsuleInterface::class);
        $capsule->expects(self::never())->method('getDatabase');

        (new OrmDefaultReconcileSpaceMembershipStates20260813150000())
            ->withCapsule($capsule)
            ->down();
    }

    /** @return list<string> */
    private function runUp(): array
    {
        $executedSql = [];
        $database    = $this->createMock(DatabaseInterface::class);
        $database->expects(self::exactly(2))
            ->method('execute')
            ->willReturnCallback(static function (string $sql) use (&$executedSql): int {
                $executedSql[] = $sql;

                return 0;
            });

        $capsule = $this->createMock(CapsuleInterface::class);
        $capsule->expects(self::exactly(2))
            ->method('getDatabase')
            ->willReturn($database);

        (new OrmDefaultReconcileSpaceMembershipStates20260813150000())
            ->withCapsule($capsule)
            ->up();

        return $executedSql;
    }
}
