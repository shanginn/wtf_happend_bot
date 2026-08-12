<?php

declare(strict_types=1);

namespace Tests\Space\Runtime;

use Bot\Space\Runtime\SpaceRuntimeSnapshotLoaderActivity;
use Bot\Space\Runtime\SpaceRuntimeSnapshotRequest;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\StatementInterface;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class SpaceRuntimeSnapshotLoaderActivityTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testExecutableCapsulesAreRejectedFromReleaseManifests(): void
    {
        $method   = new ReflectionMethod(SpaceRuntimeSnapshotLoaderActivity::class, 'capsules');
        $manifest = [
            'capsuleRuntimeImageBuildId' => '00000000-0000-4000-8000-000000000000',
            'capsules'                   => [['digest' => 'sha256:' . str_repeat('a', 64)]],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Executable capsules are disabled');
        $method->invoke(null, $manifest);
    }

    public function testNoCodeReleaseAcceptsAnEmptyCapsuleList(): void
    {
        self::assertSame(
            [],
            (new ReflectionMethod(SpaceRuntimeSnapshotLoaderActivity::class, 'capsules'))->invoke(
                null,
                ['capsules' => []],
            ),
        );
    }

    public function testCachedSnapshotWithExecutableCodeFailsClosed(): void
    {
        $database  = Mockery::mock(DatabaseInterface::class);
        $statement = Mockery::mock(StatementInterface::class);
        $database->shouldReceive('query')->once()->andReturn($statement);
        $statement->shouldReceive('fetch')->once()->andReturn([
            'payload_json' => json_encode([
                'spaceId'             => 'spc_test',
                'capsuleArtifactRefs' => [[
                    'digest' => 'sha256:' . str_repeat('a', 64),
                ]],
                'capsuleRuntimeImageBuildId' => '00000000-0000-4000-8000-000000000000',
            ], \JSON_THROW_ON_ERROR),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Executable capsules are disabled');
        (new SpaceRuntimeSnapshotLoaderActivity($database, []))->loadSnapshot(
            new SpaceRuntimeSnapshotRequest('spc_test', 'batch_test'),
        );
    }

    public function testSkillDigestPinsAllEnabledAndDisabledSkillContent(): void
    {
        $skills = [[
            'name'        => 'tone',
            'description' => 'Отвечает кратко.',
            'body'        => 'Keep answers short.',
            'enabled'     => false,
        ]];
        $digest = 'sha256:' . hash('sha256', json_encode(
            $skills,
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        ));
        $method = new ReflectionMethod(SpaceRuntimeSnapshotLoaderActivity::class, 'assertSkillsIntegrity');

        $method->invoke(null, ['skillsDigest' => $digest], $skills);
        self::assertTrue(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('do not match the release manifest');
        $method->invoke(null, ['skillsDigest' => $digest], [[...$skills[0], 'body' => 'Changed.']]);
    }

    public function testNonEmptySkillSetCannotUseLegacyManifestWithoutDigest(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no valid skills digest');

        (new ReflectionMethod(SpaceRuntimeSnapshotLoaderActivity::class, 'assertSkillsIntegrity'))->invoke(
            null,
            ['capsules' => []],
            [[
                'name'        => 'late',
                'description' => 'Late insert.',
                'body'        => 'Must be rejected.',
                'enabled'     => true,
            ]],
        );
    }
}
