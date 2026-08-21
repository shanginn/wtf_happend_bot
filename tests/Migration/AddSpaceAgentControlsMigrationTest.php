<?php

declare(strict_types=1);

namespace Tests\Migration;

require_once dirname(__DIR__, 2)
    . '/migrations/20260821.120000_0_0_default_add_space_agent_controls.php';

use Cycle\Database\DatabaseInterface;
use Cycle\Migrations\CapsuleInterface;
use Migration\OrmDefaultAddSpaceAgentControls20260821120000;
use PHPUnit\Framework\TestCase;

final class AddSpaceAgentControlsMigrationTest extends TestCase
{
    public function testExistingActiveSpacesStayPausedAcrossTheRepairCutover(): void
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

        (new OrmDefaultAddSpaceAgentControls20260821120000())
            ->withCapsule($capsule)
            ->up();

        self::assertStringContainsString(
            'ADD COLUMN agent_paused boolean NOT NULL DEFAULT false',
            $sql[0],
        );
        self::assertStringContainsString('SET agent_paused = true', $sql[1]);
        self::assertStringContainsString("WHERE status = 'active'", $sql[1]);
    }
}
