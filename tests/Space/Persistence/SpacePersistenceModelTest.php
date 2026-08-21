<?php

declare(strict_types=1);

namespace Tests\Space\Persistence;

use Bot\Entity\Space;
use Bot\Entity\SpaceMemoryVersion;
use Bot\Entity\SpaceRelease;
use Bot\Space\Persistence\SpaceActivationSnapshot;
use Bot\Space\Persistence\SpaceId;
use Bot\Space\Persistence\SpaceRecordId;
use Bot\Space\Persistence\SpaceReleaseSeed;
use Bot\Space\Runtime\SpaceCapabilityPolicy;
use InvalidArgumentException;
use Tests\TestCase;

final class SpacePersistenceModelTest extends TestCase
{
    public function testCreatesOpaqueSpaceIds(): void
    {
        $first  = SpaceId::new();
        $second = SpaceId::new();

        self::assertMatchesRegularExpression('/^spc_[a-f0-9]{40}$/', $first);
        self::assertNotSame($first, $second);
        self::assertSame($first, SpaceId::assert($first));
    }

    public function testRecordIdsAndReleaseDigestsAreDeterministic(): void
    {
        $seed = new SpaceReleaseSeed(
            model: 'deepseek/deepseek-v4-pro',
            prompt: 'Be useful.',
            personalityJson: '{"tone":"concise"}',
        );

        self::assertSame(
            SpaceRecordId::forSeed('same input'),
            SpaceRecordId::forSeed('same input'),
        );
        self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', $seed->digest());
        self::assertSame($seed->digest(), $seed->digest());
    }

    public function testReleaseSeedRejectsInvalidJson(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SpaceReleaseSeed(
            model: 'deepseek/deepseek-v4-pro',
            prompt: 'Be useful.',
            manifestJson: '{broken',
        );
    }

    public function testReleaseSeedRejectsArrayShapedReleaseMetadata(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SpaceReleaseSeed(
            model: 'deepseek/deepseek-v4-pro',
            prompt: '',
            personalityJson: '[]',
        );
    }

    public function testReleaseSeedKeepsHostCapabilityPolicyImmutable(): void
    {
        $seed = new SpaceReleaseSeed(
            model: 'deepseek/deepseek-v4-pro',
            prompt: '',
        );

        self::assertSame(SpaceCapabilityPolicy::JSON, $seed->capabilityPolicyJson);

        $this->expectException(InvalidArgumentException::class);
        new SpaceReleaseSeed(
            model: 'deepseek/deepseek-v4-pro',
            prompt: '',
            capabilityPolicyJson: '{"version":1,"capsuleNetwork":"allow","crossSpaceReads":false}',
        );
    }

    public function testSpaceStartsWithoutReleaseAndWithDreamEnabled(): void
    {
        $space = new Space(SpaceId::new());

        self::assertNull($space->activeReleaseId);
        self::assertSame(0, $space->releaseGeneration);
        self::assertSame(0, $space->memoryRevision);
        self::assertTrue($space->dreamEnabled);
        self::assertFalse($space->agentPaused);
    }

    public function testMemoryVersionPreservesSourceTimestamp(): void
    {
        $memory = new SpaceMemoryVersion(
            id: '600ab608-9a62-424e-a1f4-89f00e146ffd',
            spaceId: SpaceId::new(),
            revision: 7,
            participantKey: 'telegram_user:42',
            participantLabel: 'telegram_user:42',
            memory: 'Prefers concise replies.',
            quote: 'Keep it short.',
            context: 'Discussing answer style.',
            createdAt: 100,
            sourceUpdatedAt: 200,
        );

        self::assertSame(7, $memory->revision);
        self::assertSame(100, $memory->createdAt);
        self::assertSame(200, $memory->sourceUpdatedAt);
        self::assertSame(SpaceMemoryVersion::STATUS_ACTIVE, $memory->status);
    }

    public function testActivationSnapshotPinsReleaseAndMemoryRevisions(): void
    {
        $snapshot = new SpaceActivationSnapshot(
            spaceId: SpaceId::new(),
            releaseId: 'release-2',
            releaseGeneration: 2,
            memoryRevision: 11,
        );

        self::assertSame(2, $snapshot->releaseGeneration);
        self::assertSame(11, $snapshot->memoryRevision);
    }

    public function testActivationSnapshotRejectsMissingActiveRelease(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SpaceActivationSnapshot(
            spaceId: SpaceId::new(),
            releaseId: '',
            releaseGeneration: 1,
            memoryRevision: 0,
        );
    }

    public function testReleaseStartsWithImmutableContentAndExplicitLifecycle(): void
    {
        $release = new SpaceRelease(
            id: 'b60cb178-7bc0-4270-bf20-53cc16c03dd0',
            spaceId: SpaceId::new(),
            parentReleaseId: null,
            sourceProposalId: null,
            sequence: 1,
            status: SpaceRelease::STATUS_ACTIVE,
            releaseDigest: 'sha256:release-1',
            model: 'deepseek-chat',
            prompt: 'Be useful.',
        );

        self::assertSame(1, $release->sequence);
        self::assertSame('{}', $release->manifestJson);
        self::assertSame(SpaceRelease::STATUS_ACTIVE, $release->status);
    }
}
