<?php

declare(strict_types=1);

namespace Tests\Space\Dream;

use Bot\Space\Dream\DreamActivities;
use Bot\Space\Dream\DreamPolicy;
use Bot\Space\Persistence\SpaceStore;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\StatementInterface;
use Cycle\ORM\ORMInterface;
use Mockery;
use PiPHP\Temporal\Contract\ModelCompletionGatewayInterface;
use Tests\TestCase;

final class DreamEligibleSpacesQueryTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function testEligibilityPreaggregatesReplayableUpdatesOnceAndHonorsReviewWatermark(): void
    {
        $database  = Mockery::mock(DatabaseInterface::class);
        $statement = Mockery::mock(StatementInterface::class);
        $database
            ->shouldReceive('query')
            ->once()
            ->withArgs(static function (string $sql, array $parameters): bool {
                return str_contains($sql, 'WITH recent_updates AS MATERIALIZED')
                    && str_contains($sql, 'GROUP BY record.chat_id, record.topic_id, record.created_at')
                    && str_contains($sql, '{edited_message,text}')
                    && str_contains($sql, '{edited_channel_post,caption}')
                    && str_contains($sql, 'binding_watermarks AS MATERIALIZED')
                    && str_contains($sql, "active_release.created_by = 'nightly-dream-v1'")
                    && str_contains($sql, "NOT IN ('observing', 'rollback-deferred', 'failed')")
                    && str_contains(
                        $sql,
                        'LEAST(bound_space.last_dream_at, active_release.activated_at)',
                    )
                    && str_contains($sql, 'recent.created_at >= binding.evidence_watermark')
                    && str_contains($sql, 'HAVING SUM(recent.evidence_count) >= ?')
                    && !str_contains($sql, 'OFFSET ?')
                    && count($parameters) === 6
                    && abs((int) $parameters[0] - (time() - 72 * 3600)) <= 2
                    && $parameters[1] === 6
                    && $parameters[2] === 'spc_cursor'
                    && $parameters[3] === '2026-08-12'
                    && abs((int) $parameters[4] - (time() - 3600)) <= 2
                    && $parameters[5] === 26;
            })
            ->andReturn($statement);
        $statement->shouldReceive('fetchAll')->once()->andReturn([
            ['id' => 'spc_first'],
            ['id' => 'spc_second'],
        ]);
        $activities = new DreamActivities(
            $database,
            new SpaceStore(Mockery::mock(ORMInterface::class), $database),
            Mockery::mock(ModelCompletionGatewayInterface::class),
        );

        $page = $activities->listEligibleSpaces(
            dreamDate: '2026-08-12',
            policy: new DreamPolicy(),
            limit: 25,
            cursor: 'spc_cursor',
        );

        self::assertSame(['spc_first', 'spc_second'], $page->spaceIds);
        self::assertNull($page->nextCursor);
    }
}
