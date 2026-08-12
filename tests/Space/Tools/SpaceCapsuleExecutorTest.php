<?php

declare(strict_types=1);

namespace Tests\Space\Tools;

use Bot\Space\Sandbox\SandboxBrokerInterface;
use Bot\Space\Sandbox\SandboxExecutionRequest;
use Bot\Space\Sandbox\SandboxExecutionResult;
use Bot\Space\Tools\SpaceCapsuleExecutor;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\StatementInterface;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SpaceCapsuleExecutorTest extends TestCase
{
    private const string IMAGE_BUILD_ID = '00000000-0000-4000-8000-000000000000';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function itPinsTheRuntimeImageAndReturnsNormalizedJsonOnly(): void
    {
        $broker = Mockery::mock(SandboxBrokerInterface::class);
        $broker
            ->shouldReceive('execute')
            ->once()
            ->withArgs(static fn (SandboxExecutionRequest $request): bool =>
                $request->imageBuildId === self::IMAGE_BUILD_ID
                && $request->toArray()['runtime'] === ['imageBuildId' => self::IMAGE_BUILD_ID])
            ->andReturn(self::sandboxResult(stdout: " {\n  \"ok\": true\n} \n"));

        self::assertSame('{"ok":true}', self::executor($broker)->execute(
            spaceId: 'space-001',
            releaseId: 'release-001',
            releaseDigest: 'sha256:' . str_repeat('a', 64),
            name: 'normalize',
            arguments: [],
            idempotencyKey: 'call-001',
        ));
    }

    #[Test]
    public function itRejectsSideChannelsAndNonJsonStdout(): void
    {
        foreach ([
            self::sandboxResult(stdout: 'not-json'),
            self::sandboxResult(stdout: '{"ok":true}', stderr: 'debug'),
            self::sandboxResult(stdout: '{"ok":true}', stdoutTruncated: true),
            self::sandboxResult(stdout: '{"ok":true}', artifacts: [[
                'path' => 'data.txt',
                'ref' => 'sha256:' . str_repeat('d', 64),
                'sha256' => str_repeat('d', 64),
                'sizeBytes' => 1,
            ]]),
        ] as $result) {
            $broker = Mockery::mock(SandboxBrokerInterface::class);
            $broker->shouldReceive('execute')->once()->andReturn($result);

            self::assertStringContainsString('failed:', self::executor($broker)->execute(
                spaceId: 'space-001',
                releaseId: 'release-001',
                releaseDigest: 'sha256:' . str_repeat('a', 64),
                name: 'normalize',
                arguments: [],
                idempotencyKey: 'call-' . spl_object_id($result),
            ));
        }
    }

    private static function executor(SandboxBrokerInterface $broker): SpaceCapsuleExecutor
    {
        $database = Mockery::mock(DatabaseInterface::class);
        $statement = Mockery::mock(StatementInterface::class);
        $database->shouldReceive('query')->once()->andReturn($statement);
        $statement->shouldReceive('fetch')->once()->andReturn([
            'release_digest' => 'sha256:' . str_repeat('a', 64),
            'manifest_json' => json_encode([
                'capsuleRuntimeImageBuildId' => self::IMAGE_BUILD_ID,
                'capsules' => [[
                    'name' => 'normalize',
                    'description' => 'Normalize JSON.',
                    'digest' => 'sha256:' . str_repeat('b', 64),
                    'entrypoint' => ['/data/capsule/run.mjs'],
                    'parametersSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'value' => ['type' => 'string'],
                        ],
                        'additionalProperties' => false,
                    ],
                ]],
            ], \JSON_THROW_ON_ERROR),
        ]);

        return new SpaceCapsuleExecutor($database, $broker, self::IMAGE_BUILD_ID);
    }

    /**
     * @param list<array{path: string, ref: string, sha256: string, sizeBytes: int}> $artifacts
     */
    private static function sandboxResult(
        string $stdout,
        string $stderr = '',
        bool $stdoutTruncated = false,
        array $artifacts = [],
    ): SandboxExecutionResult {
        return SandboxExecutionResult::fromArray([
            'apiVersion' => SandboxExecutionRequest::API_VERSION,
            'runId' => 'run_' . substr(hash('sha256', 'call-001'), 0, 48),
            'status' => 'completed',
            'exitCode' => 0,
            'signal' => null,
            'stdout' => [
                'text' => $stdout,
                'bytesSeen' => strlen($stdout),
                'bytesCaptured' => strlen($stdout),
                'truncated' => $stdoutTruncated,
            ],
            'stderr' => [
                'text' => $stderr,
                'bytesSeen' => strlen($stderr),
                'bytesCaptured' => strlen($stderr),
                'truncated' => false,
            ],
            'artifacts' => $artifacts,
            'error' => null,
            'audit' => [
                'requestSha256' => str_repeat('c', 64),
                'capsuleSha256' => str_repeat('b', 64),
                'releaseDigest' => 'sha256:' . str_repeat('a', 64),
                'imageBuildId' => self::IMAGE_BUILD_ID,
                'gondolinCommit' => '10b510625dde73cbfd15ac2fc1ae7b8ef642c62c',
                'vmId' => 'vm-001',
                'startedAt' => '2026-08-12T00:00:00.000Z',
                'finishedAt' => '2026-08-12T00:00:01.000Z',
                'durationMs' => 1000,
            ],
        ]);
    }
}
