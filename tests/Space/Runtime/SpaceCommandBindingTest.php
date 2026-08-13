<?php

declare(strict_types=1);

namespace Tests\Space\Runtime;

use Bot\Space\Runtime\SpaceCommandBinding;
use Bot\Space\Runtime\SpaceRuntimeSnapshot;
use InvalidArgumentException;
use Temporal\DataConverter\DataConverter;
use Tests\TestCase;

final class SpaceCommandBindingTest extends TestCase
{
    public function testSnapshotLooksUpCanonicalTelegramCommandNames(): void
    {
        $binding  = self::binding('dimannews');
        $snapshot = new SpaceRuntimeSnapshot(
            snapshotId: 'snp_test',
            spaceId: 'spc_test',
            releaseId: 'rel_test',
            releaseDigest: 'sha256:' . str_repeat('a', 64),
            model: 'test/model',
            systemPrompt: 'Test prompt.',
            tools: [],
            commands: [$binding],
        );

        self::assertSame($binding, $snapshot->command('dimannews'));
        self::assertSame($binding, $snapshot->command('/DIMANNEWS@wtf_bot'));
        self::assertNull($snapshot->command('/unknown'));
    }

    public function testSnapshotRejectsDuplicateOrUnsortedCommands(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('uniquely sorted');

        new SpaceRuntimeSnapshot(
            snapshotId: 'snp_test',
            spaceId: 'spc_test',
            releaseId: 'rel_test',
            releaseDigest: 'sha256:' . str_repeat('a', 64),
            model: 'test/model',
            systemPrompt: 'Test prompt.',
            tools: [],
            commands: [self::binding('news'), self::binding('dimannews')],
        );
    }

    public function testSnapshotCommandRemainsUsableAfterDefaultTemporalRoundTrip(): void
    {
        $snapshot = new SpaceRuntimeSnapshot(
            snapshotId: 'snp_test',
            spaceId: 'spc_test',
            releaseId: 'rel_test',
            releaseDigest: 'sha256:' . str_repeat('a', 64),
            model: 'test/model',
            systemPrompt: 'Test prompt.',
            tools: [],
            commands: [self::binding('dimannews')],
        );
        $converter = DataConverter::createDefault();

        $decoded = $converter->fromPayload(
            $converter->toPayload($snapshot),
            SpaceRuntimeSnapshot::class,
        );

        self::assertInstanceOf(SpaceRuntimeSnapshot::class, $decoded);
        self::assertInstanceOf(SpaceCommandBinding::class, $decoded->commands[0]);
        self::assertSame(
            'Use the complete pinned format.',
            $decoded->command('/DIMANNEWS')?->instructions,
        );
    }

    public function testBindingRejectsUnsupportedParameterSchemas(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported');

        new SpaceCommandBinding(
            name: 'dimannews',
            description: 'Generate news.',
            instructions: 'Use the complete pinned format.',
            parametersSchema: [
                'type'       => 'object',
                'properties' => [],
                'format'     => 'unsupported-at-object-level',
            ],
        );
    }

    private static function binding(string $name): SpaceCommandBinding
    {
        return new SpaceCommandBinding(
            name: $name,
            description: 'Generate news.',
            instructions: 'Use the complete pinned format.',
            parametersSchema: [
                'type'                 => 'object',
                'properties'           => [],
                'additionalProperties' => false,
            ],
        );
    }
}
