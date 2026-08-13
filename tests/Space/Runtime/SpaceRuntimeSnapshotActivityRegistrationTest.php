<?php

declare(strict_types=1);

namespace Tests\Space\Runtime;

use Bot\Space\Runtime\SpaceRuntimeSnapshotLoaderActivity;
use Bot\Space\Runtime\SpaceRuntimeSnapshotLoaderActivityInterface;
use Bot\Space\Runtime\SpaceRuntimeSnapshotRequest;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\StatementInterface;
use Mockery;
use Spiral\Attributes\AttributeReader;
use Temporal\Internal\Declaration\Dispatcher\Dispatcher;
use Temporal\Internal\Declaration\Reader\ActivityReader;
use Tests\TestCase;

final class SpaceRuntimeSnapshotActivityRegistrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testWorkerDeclarationRegistersAndDispatchesTheConcreteActivityMethod(): void
    {
        $declarations = file_get_contents(dirname(__DIR__, 3) . '/config/declarations.php');
        self::assertIsString($declarations);
        self::assertStringContainsString(
            'SpaceRuntimeSnapshotLoaderActivity::class => function () use (',
            $declarations,
        );
        self::assertDoesNotMatchRegularExpression(
            '/SpaceRuntimeSnapshotLoaderActivityInterface::class\s*=>/',
            $declarations,
        );

        $prototypes = (new ActivityReader(new AttributeReader()))->fromClass(
            SpaceRuntimeSnapshotLoaderActivity::class,
        );
        self::assertCount(1, $prototypes);
        self::assertSame('SpaceRuntime.loadSnapshot', $prototypes[0]->getID());

        $handler = $prototypes[0]->getHandler();
        self::assertSame(SpaceRuntimeSnapshotLoaderActivity::class, $handler->getDeclaringClass()->getName());
        self::assertFalse($handler->isAbstract());

        $payload = [
            'snapshotId'                 => 'snp_test',
            'spaceId'                    => 'spc_test',
            'releaseId'                  => 'rel_test',
            'releaseDigest'              => 'sha256:' . str_repeat('a', 64),
            'model'                      => 'test-model',
            'systemPrompt'               => 'Test prompt.',
            'tools'                      => [],
            'commands'                   => [],
            'capsuleArtifactRefs'        => [],
            'capsuleRuntimeImageBuildId' => null,
            'memoryRevision'             => '1',
            'capabilityPolicyRevision'   => 'sha256:' . str_repeat('b', 64),
        ];
        $statement = Mockery::mock(StatementInterface::class);
        $statement->shouldReceive('fetch')->once()->andReturn([
            'payload_json' => json_encode($payload, \JSON_THROW_ON_ERROR),
        ]);
        $database = Mockery::mock(DatabaseInterface::class);
        $database->shouldReceive('query')->once()->andReturn($statement);
        $activity = new SpaceRuntimeSnapshotLoaderActivity($database, []);

        $snapshot = (new Dispatcher($handler))->dispatch($activity, [
            new SpaceRuntimeSnapshotRequest('spc_test', 'batch_test'),
        ]);

        self::assertSame('snp_test', $snapshot->snapshotId);
        self::assertInstanceOf(SpaceRuntimeSnapshotLoaderActivityInterface::class, $activity);
    }
}
