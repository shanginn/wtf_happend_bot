<?php

declare(strict_types=1);

namespace Bot\Entity;

use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use Cycle\Annotated\Annotation\ForeignKey;
use Cycle\Annotated\Annotation\Table\Index;

#[Entity(table: 'space_promotion_events')]
#[ForeignKey(target: Space::class, innerKey: 'spaceId', action: 'NO ACTION', indexCreate: false)]
#[ForeignKey(target: SpaceUpgradeProposal::class, innerKey: 'proposalId', action: 'NO ACTION', indexCreate: false)]
#[ForeignKey(target: SpaceRelease::class, innerKey: 'fromReleaseId', action: 'NO ACTION', indexCreate: false)]
#[ForeignKey(target: SpaceRelease::class, innerKey: 'toReleaseId', action: 'NO ACTION', indexCreate: false)]
#[Index(['spaceId', 'releaseGenerationAfter'], unique: true)]
#[Index(['proposalId'])]
class SpacePromotionEvent
{
    public const string ACTION_PROMOTE  = 'promote';
    public const string ACTION_ROLLBACK = 'rollback';

    public function __construct(
        #[Column(type: 'text', primary: true)]
        public string $id,
        #[Column(type: 'text')]
        public string $spaceId,
        #[Column(type: 'text', nullable: true)]
        public ?string $proposalId,
        #[Column(type: 'text', nullable: true)]
        public ?string $fromReleaseId,
        #[Column(type: 'text')]
        public string $toReleaseId,
        #[Column(type: 'text')]
        public string $action,
        #[Column(type: 'bigInteger')]
        public int $releaseGenerationBefore,
        #[Column(type: 'bigInteger')]
        public int $releaseGenerationAfter,
        #[Column(type: 'text')]
        public string $actor,
        #[Column(type: 'text')]
        public string $policyDecisionJson = '{}',
        #[Column(type: 'bigInteger')]
        public int $createdAt = 0,
    ) {
        $this->createdAt = $this->createdAt === 0 ? time() : $this->createdAt;
    }
}
