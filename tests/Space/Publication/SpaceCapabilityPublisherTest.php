<?php

declare(strict_types=1);

namespace Tests\Space\Publication;

use Bot\Space\Persistence\SpaceReleaseSeed;
use Bot\Space\Runtime\SpaceCapabilityPolicy;
use Bot\Space\Runtime\SpaceRuntimeSnapshotLoaderActivity;
use RuntimeException;
use Tests\TestCase;

final class SpaceCapabilityPublisherTest extends TestCase
{
    public function testSharedCandidateMaterializerEnforcesTheExactSnapshotByteBoundary(): void
    {
        $seed = new SpaceReleaseSeed(
            model: 'model-test',
            prompt: 'Base overlay.',
            manifestJson: '{"capsules":[]}',
            capabilityPolicyJson: SpaceCapabilityPolicy::JSON,
            createdBy: 'live-space-capability-v1',
        );
        $materialize = static fn (int $fillerBytes): array => SpaceRuntimeSnapshotLoaderActivity::materializePayload(
            spaceId: 'spc_0123456789abcdef0123456789abcdef01234567',
            batchId: 'publication-boundary',
            releaseGeneration: 2,
            memoryRevision: '0',
            release: [
                'id'                     => 'rel_boundary',
                'release_digest'         => $seed->digest(),
                'model'                  => $seed->model,
                'prompt'                 => $seed->prompt,
                'personality_json'       => $seed->personalityJson,
                'manifest_json'          => $seed->manifestJson,
                'capability_policy_json' => $seed->capabilityPolicyJson,
                'artifact_digest'        => null,
                'created_by'             => $seed->createdBy,
            ],
            skillRows: [],
            tools: [['filler' => str_repeat('x', $fillerBytes)]],
        );

        $accepted = 0;
        $rejected = 600_000;
        while ($accepted + 1 < $rejected) {
            $candidate = intdiv($accepted + $rejected, 2);

            try {
                $materialize($candidate);
                $accepted = $candidate;
            } catch (RuntimeException $error) {
                self::assertStringContainsString('safe payload budget', $error->getMessage());
                $rejected = $candidate;
            }
        }

        $payload = $materialize($accepted);
        self::assertLessThanOrEqual(
            512_000,
            strlen(json_encode(
                $payload,
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
            )),
        );
        self::assertSame($accepted + 1, $rejected);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('safe payload budget');
        $materialize($rejected);
    }
}
