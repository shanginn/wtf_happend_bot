<?php

declare(strict_types=1);

namespace Tests\Space\Persistence;

use Bot\Space\Persistence\SpaceBindingKey;
use Bot\Space\Persistence\SpaceId;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SpaceBindingKeyTest extends TestCase
{
    /**
     * @return iterable<string, array{int|string|null}>
     */
    public static function rootThreadProvider(): iterable
    {
        yield 'null' => [null];
        yield 'zero integer' => [0];
        yield 'zero string' => ['0'];
        yield 'empty string' => [''];
    }

    #[DataProvider('rootThreadProvider')]
    public function testNormalizesRootThread(null|int|string $threadId): void
    {
        $key = new SpaceBindingKey('default', 'Telegram', -100123, $threadId);

        self::assertSame('telegram', $key->platform);
        self::assertSame('-100123', $key->externalConversationId);
        self::assertSame('', $key->externalThreadId);
        self::assertTrue($key->isRoot());
    }

    public function testPreservesTopicThread(): void
    {
        $key = new SpaceBindingKey('default', 'telegram', -100123, 42);

        self::assertSame('42', $key->externalThreadId);
        self::assertFalse($key->isRoot());
    }

    public function testBuildsSameOpaqueIdentityAsRuntimeResolver(): void
    {
        $key       = new SpaceBindingKey('default', 'telegram', -100123);
        $canonical = implode("\0", ['space-v1', 'telegram', 'default', '-100123', '']);

        self::assertSame(
            'spc_' . substr(hash('sha256', $canonical), 0, 40),
            SpaceId::forBinding($key),
        );
    }

    public function testRejectsMissingTenantDimension(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SpaceBindingKey('', 'telegram', -100123);
    }
}
