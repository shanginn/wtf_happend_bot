<?php

declare(strict_types=1);

namespace Bot\Entity;

use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use Cycle\Annotated\Annotation\ForeignKey;
use Cycle\Annotated\Annotation\Table\Index;

#[Entity(table: 'space_sandbox_jobs')]
#[ForeignKey(target: Space::class, innerKey: 'spaceId', action: 'NO ACTION', indexCreate: false)]
#[ForeignKey(target: SpaceDreamRun::class, innerKey: 'dreamRunId', action: 'NO ACTION', indexCreate: false)]
#[ForeignKey(target: SpaceUpgradeProposal::class, innerKey: 'proposalId', action: 'NO ACTION', indexCreate: false)]
#[ForeignKey(target: SpaceRelease::class, innerKey: 'releaseId', action: 'NO ACTION', indexCreate: false)]
#[Index(['idempotencyKey'], unique: true)]
#[Index(['spaceId', 'status'])]
#[Index(['dreamRunId'])]
#[Index(['proposalId'])]
class SpaceSandboxJob
{
    public const string STATUS_CANCELLED = 'cancelled';
    public const string STATUS_COMPLETED = 'completed';
    public const string STATUS_FAILED    = 'failed';
    public const string STATUS_PENDING   = 'pending';
    public const string STATUS_RUNNING   = 'running';

    public function __construct(
        #[Column(type: 'text', primary: true)]
        public string $id,
        #[Column(type: 'text')]
        public string $spaceId,
        #[Column(type: 'text', nullable: true)]
        public ?string $dreamRunId,
        #[Column(type: 'text', nullable: true)]
        public ?string $proposalId,
        #[Column(type: 'text')]
        public string $releaseId,
        #[Column(type: 'text')]
        public string $jobType,
        #[Column(type: 'text')]
        public string $idempotencyKey,
        #[Column(type: 'text')]
        public string $requestFingerprint,
        #[Column(type: 'text')]
        public string $requestJson,
        #[Column(type: 'text')]
        public string $status = self::STATUS_PENDING,
        #[Column(type: 'text')]
        public string $runtimeImageDigest = '',
        #[Column(type: 'text')]
        public string $resourceLimitsJson = '{}',
        #[Column(type: 'text')]
        public string $capabilityPolicyJson = '{}',
        #[Column(type: 'text', nullable: true)]
        public ?string $inputArtifactUri = null,
        #[Column(type: 'text', nullable: true)]
        public ?string $outputArtifactUri = null,
        #[Column(type: 'text', nullable: true)]
        public ?string $resultJson = null,
        #[Column(type: 'text', nullable: true)]
        public ?string $error = null,
        #[Column(type: 'bigInteger')]
        public int $createdAt = 0,
        #[Column(type: 'bigInteger', nullable: true)]
        public ?int $startedAt = null,
        #[Column(type: 'bigInteger', nullable: true)]
        public ?int $heartbeatAt = null,
        #[Column(type: 'bigInteger', nullable: true)]
        public ?int $completedAt = null,
    ) {
        $this->createdAt = $this->createdAt === 0 ? time() : $this->createdAt;
    }
}
