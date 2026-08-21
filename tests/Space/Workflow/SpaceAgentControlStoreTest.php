<?php

declare(strict_types=1);

namespace Tests\Space\Workflow;

use Bot\Space\Workflow\SpaceAgentControlStore;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\StatementInterface;
use Mockery;
use Tests\TestCase;

final class SpaceAgentControlStoreTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testReadsAndUpdatesTheDurablePauseFence(): void
    {
        $spaceId   = 'spc_0123456789abcdef0123456789abcdef01234567';
        $database  = Mockery::mock(DatabaseInterface::class);
        $statement = Mockery::mock(StatementInterface::class);
        $database->shouldReceive('query')
            ->once()
            ->with('SELECT agent_paused FROM agent_spaces WHERE id = ?', [$spaceId])
            ->andReturn($statement);
        $statement->shouldReceive('fetch')->once()->andReturn(['agent_paused' => '1']);
        $database->shouldReceive('execute')
            ->once()
            ->withArgs(static fn (string $sql, array $parameters): bool => $sql
                === 'UPDATE agent_spaces SET agent_paused = ?, updated_at = ? WHERE id = ?'
                && $parameters[0] === false
                && is_int($parameters[1])
                && $parameters[2] === $spaceId)
            ->andReturn(1);

        $store = new SpaceAgentControlStore($database);
        self::assertTrue($store->isPaused($spaceId));
        $store->setPaused($spaceId, false);
    }
}
