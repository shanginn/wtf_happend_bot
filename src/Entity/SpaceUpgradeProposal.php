<?php

declare(strict_types=1);

namespace Bot\Entity;

use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use Cycle\Annotated\Annotation\ForeignKey;
use Cycle\Annotated\Annotation\Table\Index;

#[Entity(table: 'space_upgrade_proposals')]
#[ForeignKey(target: Space::class, innerKey: 'spaceId', action: 'NO ACTION', indexCreate: false)]
#[ForeignKey(target: SpaceDreamRun::class, innerKey: 'dreamRunId', action: 'NO ACTION', indexCreate: false)]
#[ForeignKey(target: SpaceRelease::class, innerKey: 'baselineReleaseId', action: 'NO ACTION', indexCreate: false)]
#[ForeignKey(target: SpaceRelease::class, innerKey: 'candidateReleaseId', action: 'NO ACTION', indexCreate: false)]
#[Index(['spaceId', 'status'])]
#[Index(['dreamRunId'])]
#[Index(['candidateReleaseId'], unique: true)]
final class SpaceUpgradeProposal
{
    public const string STATUS_ACCEPTED   = 'accepted';
    public const string STATUS_EVALUATING = 'evaluating';
    public const string STATUS_PROPOSED   = 'proposed';
    public const string STATUS_REJECTED   = 'rejected';

    public function __construct(
        #[Column(type: 'text', primary: true)]
        public string $id,
        #[Column(type: 'text')]
        public string $spaceId,
        #[Column(type: 'text')]
        public string $dreamRunId,
        #[Column(type: 'text')]
        public string $baselineReleaseId,
        #[Column(type: 'text')]
        public string $candidateReleaseId,
        #[Column(type: 'text')]
        public string $hypothesis,
        #[Column(type: 'text')]
        public string $riskClass,
        #[Column(type: 'text')]
        public string $proposalFingerprint,
        #[Column(type: 'text')]
        public string $proposalJson,
        #[Column(type: 'text')]
        public string $status = self::STATUS_PROPOSED,
        #[Column(type: 'text')]
        public string $requestedCapabilitiesJson = '[]',
        #[Column(type: 'text')]
        public string $evidenceJson = '{}',
        #[Column(type: 'bigInteger')]
        public int $createdAt = 0,
        #[Column(type: 'bigInteger', nullable: true)]
        public ?int $decidedAt = null,
    ) {
        $this->createdAt = $this->createdAt === 0 ? time() : $this->createdAt;
    }
}
