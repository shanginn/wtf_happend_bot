<?php

declare(strict_types=1);

namespace Bot\Entity;

use Bot\Entity\Space\SpaceMemoryVersionRepository;
use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use Cycle\Annotated\Annotation\ForeignKey;
use Cycle\Annotated\Annotation\Table\Index;

#[Entity(repository: SpaceMemoryVersionRepository::class, table: 'space_memory_versions')]
#[ForeignKey(target: Space::class, innerKey: 'spaceId', action: 'NO ACTION', indexCreate: false)]
#[ForeignKey(target: SpaceMemoryVersion::class, innerKey: 'supersedesMemoryId', action: 'NO ACTION', indexCreate: false)]
#[Index(['spaceId', 'revision'], unique: true)]
#[Index(['spaceId', 'idempotencyKey'], unique: true)]
#[Index(['spaceId', 'participantKey', 'status'])]
#[Index(['supersedesMemoryId'])]
class SpaceMemoryVersion
{
    public const string STATUS_ACTIVE     = 'active';
    public const string STATUS_FORGOTTEN  = 'forgotten';
    public const string STATUS_SUPERSEDED = 'superseded';

    public function __construct(
        #[Column(type: 'text', primary: true)]
        public string $id,
        #[Column(type: 'text')]
        public string $spaceId,
        #[Column(type: 'bigInteger')]
        public int $revision,
        #[Column(type: 'text')]
        public string $participantKey,
        #[Column(type: 'text')]
        public string $participantLabel,
        #[Column(type: 'text')]
        public string $memory,
        #[Column(type: 'text')]
        public string $quote,
        #[Column(type: 'text')]
        public string $context,
        #[Column(type: 'text')]
        public string $status = self::STATUS_ACTIVE,
        #[Column(type: 'text', nullable: true)]
        public ?string $idempotencyKey = null,
        #[Column(type: 'text', nullable: true)]
        public ?string $supersedesMemoryId = null,
        #[Column(type: 'text')]
        public string $provenanceJson = '{}',
        #[Column(type: 'integer', nullable: true)]
        public ?int $confidencePermille = null,
        #[Column(type: 'bigInteger')]
        public int $createdAt = 0,
        #[Column(type: 'bigInteger')]
        public int $sourceUpdatedAt = 0,
    ) {
        $now                   = time();
        $this->createdAt       = $this->createdAt === 0 ? $now : $this->createdAt;
        $this->sourceUpdatedAt = $this->sourceUpdatedAt === 0 ? $this->createdAt : $this->sourceUpdatedAt;
    }
}
