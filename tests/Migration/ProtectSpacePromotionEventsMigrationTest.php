<?php

declare(strict_types=1);

namespace Tests\Migration;

require_once dirname(__DIR__, 2)
    . '/migrations/20260813.160000_0_0_default_protect_space_promotion_events.php';

use Cycle\Database\DatabaseInterface;
use Cycle\Migrations\CapsuleInterface;
use Migration\OrmDefaultProtectSpacePromotionEvents20260813160000;
use PHPUnit\Framework\TestCase;

final class ProtectSpacePromotionEventsMigrationTest extends TestCase
{
    public function testUpMakesTheAuthorityLedgerAppendOnly(): void
    {
        $sql      = [];
        $database = $this->createMock(DatabaseInterface::class);
        $database->expects(self::exactly(2))->method('execute')
            ->willReturnCallback(static function (string $statement) use (&$sql): int {
                $sql[] = $statement;

                return 0;
            });
        $capsule = $this->createMock(CapsuleInterface::class);
        $capsule->expects(self::exactly(2))->method('getDatabase')->willReturn($database);

        (new OrmDefaultProtectSpacePromotionEvents20260813160000())
            ->withCapsule($capsule)
            ->up();

        self::assertStringContainsString('RAISE EXCEPTION', $sql[0]);
        self::assertStringContainsString('BEFORE UPDATE OR DELETE ON space_promotion_events', $sql[1]);
    }

    public function testDownRemovesOnlyTheNewGuard(): void
    {
        $sql      = [];
        $database = $this->createMock(DatabaseInterface::class);
        $database->expects(self::exactly(2))->method('execute')
            ->willReturnCallback(static function (string $statement) use (&$sql): int {
                $sql[] = $statement;

                return 0;
            });
        $capsule = $this->createMock(CapsuleInterface::class);
        $capsule->expects(self::exactly(2))->method('getDatabase')->willReturn($database);

        (new OrmDefaultProtectSpacePromotionEvents20260813160000())
            ->withCapsule($capsule)
            ->down();

        self::assertSame(
            'DROP TRIGGER space_promotion_events_immutable ON space_promotion_events',
            $sql[0],
        );
        self::assertSame('DROP FUNCTION reject_space_promotion_event_mutation()', $sql[1]);
    }
}
