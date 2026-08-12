<?php

declare(strict_types=1);

namespace Bot\Entity;

use Bot\Entity\Space\SpaceReleaseRepository;
use Bot\Space\Runtime\SpaceCapabilityPolicy;
use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use Cycle\Annotated\Annotation\ForeignKey;
use Cycle\Annotated\Annotation\Table\Index;

#[Entity(repository: SpaceReleaseRepository::class, table: 'space_releases')]
#[ForeignKey(target: Space::class, innerKey: 'spaceId', action: 'NO ACTION', indexCreate: false)]
#[ForeignKey(target: SpaceRelease::class, innerKey: 'parentReleaseId', action: 'NO ACTION', indexCreate: false)]
#[Index(['spaceId', 'sequence'], unique: true)]
#[Index(['spaceId', 'status'])]
#[Index(['sourceProposalId'], unique: true)]
final class SpaceRelease
{
    public const string STATUS_ACTIVE      = 'active';
    public const string STATUS_BUILDING    = 'building';
    public const string STATUS_CANARY      = 'canary';
    public const string STATUS_DRAFT       = 'draft';
    public const string STATUS_EVALUATED   = 'evaluated';
    public const string STATUS_QUARANTINED = 'quarantined';
    public const string STATUS_REJECTED    = 'rejected';
    public const string STATUS_RETIRED     = 'retired';
    public const string STATUS_SHADOW      = 'shadow';

    public function __construct(
        #[Column(type: 'text', primary: true)]
        public string $id,
        #[Column(type: 'text')]
        public string $spaceId,
        #[Column(type: 'text', nullable: true)]
        public ?string $parentReleaseId,
        #[Column(type: 'text', nullable: true)]
        public ?string $sourceProposalId,
        #[Column(type: 'bigInteger')]
        public int $sequence,
        #[Column(type: 'text')]
        public string $status,
        #[Column(type: 'text')]
        public string $releaseDigest,
        #[Column(type: 'text')]
        public string $model,
        #[Column(type: 'text')]
        public string $prompt,
        #[Column(type: 'text')]
        public string $personalityJson = '{}',
        #[Column(type: 'text')]
        public string $manifestJson = '{}',
        #[Column(type: 'text')]
        public string $capabilityPolicyJson = SpaceCapabilityPolicy::JSON,
        #[Column(type: 'text', nullable: true)]
        public ?string $artifactDigest = null,
        #[Column(type: 'text', nullable: true)]
        public ?string $evaluationDigest = null,
        #[Column(type: 'text')]
        public string $createdBy = 'system',
        #[Column(type: 'bigInteger')]
        public int $createdAt = 0,
        #[Column(type: 'bigInteger', nullable: true)]
        public ?int $activatedAt = null,
    ) {
        $this->createdAt = $this->createdAt === 0 ? time() : $this->createdAt;
    }
}
