<?php

declare(strict_types=1);

namespace Tests\Space\Sandbox;

use Bot\Space\Sandbox\HttpSandboxBrokerClient;
use Bot\Space\Sandbox\CapsuleStageRequest;
use Bot\Space\Sandbox\SandboxExecutionRequest;
use Bot\Space\Sandbox\SandboxExecutionResult;
use Bot\Space\Sandbox\SandboxResourceLimits;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SandboxContractTest extends TestCase
{
    #[Test]
    public function requestAlwaysDeniesNetworkAndUsesImmutableReferences(): void
    {
        $payload = self::request()->toArray();

        self::assertSame(['mode' => 'deny'], $payload['network']);
        self::assertSame('sha256:' . str_repeat('a', 64), $payload['release']['digest']);
        self::assertSame('sha256:' . str_repeat('b', 64), $payload['capsule']['digest']);
        self::assertSame(
            '00000000-0000-4000-8000-000000000000',
            $payload['runtime']['imageBuildId'],
        );
        self::assertArrayNotHasKey('secrets', $payload);
        self::assertArrayNotHasKey('environment', $payload);
    }

    #[Test]
    public function clientSendsAuthenticationAndIdempotencyAndValidatesResponse(): void
    {
        $seen = null;
        $transport = static function (string $method, string $uri, array $headers, ?string $body, int $timeoutMs) use (&$seen): array {
            $seen = compact('method', 'uri', 'headers', 'body', 'timeoutMs');
            return ['status' => 200, 'body' => json_encode(self::sandboxResult(), \JSON_THROW_ON_ERROR)];
        };
        $client = new HttpSandboxBrokerClient(
            baseUri: 'http://127.0.0.1:8787',
            token: str_repeat('t', 32),
            transport: $transport,
        );

        $result = $client->execute(self::request());

        self::assertInstanceOf(SandboxExecutionResult::class, $result);
        self::assertSame('completed', $result->status);
        self::assertSame('POST', $seen['method']);
        self::assertSame('run-001', $seen['headers']['Idempotency-Key']);
        self::assertSame('Bearer ' . str_repeat('t', 32), $seen['headers']['Authorization']);
        self::assertStringNotContainsString(str_repeat('t', 32), $seen['body']);
    }

    #[Test]
    public function responseForAnotherRunIsRejected(): void
    {
        $payload = self::sandboxResult();
        $payload['runId'] = 'run-other';
        $client = new HttpSandboxBrokerClient(
            baseUri: 'http://127.0.0.1:8787',
            token: str_repeat('t', 32),
            transport: static fn (): array => [
                'status' => 200,
                'body'   => json_encode($payload, \JSON_THROW_ON_ERROR),
            ],
        );

        $this->expectExceptionMessage('different run');
        $client->execute(self::request());
    }

    #[Test]
    public function responseForAnotherRuntimeImageIsRejected(): void
    {
        $payload = self::sandboxResult();
        $payload['audit']['imageBuildId'] = '11111111-1111-4111-8111-111111111111';
        $client = new HttpSandboxBrokerClient(
            baseUri: 'http://127.0.0.1:8787',
            token: str_repeat('t', 32),
            transport: static fn (): array => [
                'status' => 200,
                'body'   => json_encode($payload, \JSON_THROW_ON_ERROR),
            ],
        );

        $this->expectExceptionMessage('runtime image');
        $client->execute(self::request());
    }

    #[Test]
    public function requestRejectsANonUuidRuntimeImage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('canonical UUID');

        new SandboxExecutionRequest(
            runId: 'run-001',
            spaceId: 'space-001',
            releaseId: 'release-001',
            releaseDigest: 'sha256:' . str_repeat('a', 64),
            capsuleDigest: 'sha256:' . str_repeat('b', 64),
            imageBuildId: 'latest',
            entrypoint: ['/data/capsule/run'],
            input: [],
        );
    }

    #[Test]
    public function requestRejectsAnUnsupportedUuidVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('canonical UUID');

        new SandboxExecutionRequest(
            runId: 'run-001',
            spaceId: 'space-001',
            releaseId: 'release-001',
            releaseDigest: 'sha256:' . str_repeat('a', 64),
            capsuleDigest: 'sha256:' . str_repeat('b', 64),
            imageBuildId: '11111111-1111-6111-8111-111111111111',
            entrypoint: ['/data/capsule/run'],
            input: [],
        );
    }

    #[Test]
    public function clientStagesCodeWithoutExecutionAuthority(): void
    {
        $seen = null;
        $client = new HttpSandboxBrokerClient(
            baseUri: 'http://127.0.0.1:8787',
            token: str_repeat('t', 32),
            transport: static function (string $method, string $uri, array $headers, ?string $body) use (&$seen): array {
                $seen = compact('method', 'uri', 'headers', 'body');
                return [
                    'status' => 200,
                    'body'   => json_encode([
                        'digest'     => 'sha256:' . str_repeat('d', 64),
                        'entrypoint' => ['/data/capsule/run.mjs'],
                    ], \JSON_THROW_ON_ERROR),
                ];
            },
        );

        $result = $client->stage(new CapsuleStageRequest(
            proposalId: 'proposal-001',
            spaceId: 'space-001',
            name: 'daily-summary',
            source: "console.log('ok');\n",
        ));

        self::assertSame('sha256:' . str_repeat('d', 64), $result->digest);
        self::assertSame('proposal-001:daily-summary', $seen['headers']['Idempotency-Key']);
        self::assertStringContainsString('/v1/capsules:stage', $seen['uri']);
        self::assertStringNotContainsString('network', $seen['body']);
        self::assertStringNotContainsString('secret', $seen['body']);
    }

    private static function request(): SandboxExecutionRequest
    {
        return new SandboxExecutionRequest(
            runId: 'run-001',
            spaceId: 'space-001',
            releaseId: 'release-001',
            releaseDigest: 'sha256:' . str_repeat('a', 64),
            capsuleDigest: 'sha256:' . str_repeat('b', 64),
            imageBuildId: '00000000-0000-4000-8000-000000000000',
            entrypoint: ['/data/capsule/run'],
            input: ['value' => 42],
            limits: new SandboxResourceLimits(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function sandboxResult(): array
    {
        return [
            'apiVersion' => SandboxExecutionRequest::API_VERSION,
            'runId'      => 'run-001',
            'status'     => 'completed',
            'exitCode'   => 0,
            'signal'     => null,
            'stdout'     => ['text' => 'ok', 'bytesSeen' => 2, 'bytesCaptured' => 2, 'truncated' => false],
            'stderr'     => ['text' => '', 'bytesSeen' => 0, 'bytesCaptured' => 0, 'truncated' => false],
            'artifacts'  => [],
            'error'      => null,
            'audit'      => [
                'requestSha256'  => str_repeat('c', 64),
                'capsuleSha256'  => str_repeat('b', 64),
                'releaseDigest'  => 'sha256:' . str_repeat('a', 64),
                'imageBuildId'   => '00000000-0000-4000-8000-000000000000',
                'gondolinCommit' => '10b510625dde73cbfd15ac2fc1ae7b8ef642c62c',
                'vmId'           => 'vm-001',
                'startedAt'      => '2026-08-12T00:00:00.000Z',
                'finishedAt'     => '2026-08-12T00:00:01.000Z',
                'durationMs'     => 1000,
            ],
        ];
    }
}
