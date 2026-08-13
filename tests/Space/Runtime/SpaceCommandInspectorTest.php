<?php

declare(strict_types=1);

namespace Tests\Space\Runtime;

use Bot\Space\Runtime\SpaceCommandInspector;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\StatementInterface;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class SpaceCommandInspectorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testReadsCompleteCommandOnlyFromExactPinnedSnapshot(): void
    {
        $database  = Mockery::mock(DatabaseInterface::class);
        $statement = Mockery::mock(StatementInterface::class);
        $database->shouldReceive('query')
            ->once()
            ->with(Mockery::type('string'), ['snp_test', 'spc_test', 'rel_test'])
            ->andReturn($statement);
        $statement->shouldReceive('fetch')->once()->andReturn([
            'payload_json' => json_encode([
                'snapshotId' => 'snp_test',
                'spaceId'    => 'spc_test',
                'releaseId'  => 'rel_test',
                'commands'   => [[
                    'name'             => 'dimannews',
                    'description'      => 'Generate Diman News.',
                    'instructions'     => 'Use the complete pinned format.',
                    'parametersSchema' => [
                        'type'                 => 'object',
                        'properties'           => [],
                        'additionalProperties' => false,
                    ],
                ]],
            ], \JSON_THROW_ON_ERROR),
        ]);

        $result = json_decode(
            (new SpaceCommandInspector($database))->inspect(
                snapshotId: 'snp_test',
                spaceId: 'spc_test',
                releaseId: 'rel_test',
                name: '/DIMANNEWS',
            ),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        self::assertTrue($result['enabled']);
        self::assertSame('dimannews', $result['command']);
        self::assertSame('Use the complete pinned format.', $result['instructions']);
        self::assertSame('rel_test', $result['releaseId']);
        self::assertMatchesRegularExpression('/\Asha256:[a-f0-9]{64}\z/D', $result['specDigest']);
    }

    public function testUnknownCommandIsAuthoritativelyDisabled(): void
    {
        $database  = Mockery::mock(DatabaseInterface::class);
        $statement = Mockery::mock(StatementInterface::class);
        $database->shouldReceive('query')->once()->andReturn($statement);
        $statement->shouldReceive('fetch')->once()->andReturn([
            'payload_json' => json_encode([
                'snapshotId' => 'snp_test',
                'spaceId'    => 'spc_test',
                'releaseId'  => 'rel_test',
                'commands'   => [],
            ], \JSON_THROW_ON_ERROR),
        ]);

        $result = json_decode(
            (new SpaceCommandInspector($database))->inspect(
                snapshotId: 'snp_test',
                spaceId: 'spc_test',
                releaseId: 'rel_test',
                name: 'missing',
            ),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        self::assertSame([
            'command'   => 'missing',
            'enabled'   => false,
            'releaseId' => 'rel_test',
        ], $result);
    }

    public function testRejectsSnapshotIdentityMismatch(): void
    {
        $database  = Mockery::mock(DatabaseInterface::class);
        $statement = Mockery::mock(StatementInterface::class);
        $database->shouldReceive('query')->once()->andReturn($statement);
        $statement->shouldReceive('fetch')->once()->andReturn([
            'payload_json' => json_encode([
                'snapshotId' => 'snp_other',
                'spaceId'    => 'spc_other',
                'releaseId'  => 'rel_other',
                'commands'   => [],
            ], \JSON_THROW_ON_ERROR),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('identity is inconsistent');
        (new SpaceCommandInspector($database))->inspect(
            snapshotId: 'snp_test',
            spaceId: 'spc_test',
            releaseId: 'rel_test',
            name: 'dimannews',
        );
    }
}
