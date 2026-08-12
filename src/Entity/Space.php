<?php

declare(strict_types=1);

namespace Bot\Entity;

use Bot\Entity\Space\SpaceRepository;
use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use Cycle\Annotated\Annotation\ForeignKey;
use Cycle\Annotated\Annotation\Table\Index;

#[Entity(repository: SpaceRepository::class, table: 'agent_spaces')]
#[ForeignKey(target: SpaceRelease::class, innerKey: 'activeReleaseId', action: 'NO ACTION', indexCreate: false)]
#[Index(['status', 'dreamEnabled'])]
#[Index(['activeReleaseId'])]
final class Space
{
    public const string STATUS_ACTIVE   = 'active';
    public const string STATUS_DISABLED = 'disabled';

    public function __construct(
        #[Column(type: 'text', primary: true)]
        public string $id,
        #[Column(type: 'text')]
        public string $status = self::STATUS_ACTIVE,
        #[Column(type: 'text', nullable: true)]
        public ?string $activeReleaseId = null,
        #[Column(type: 'bigInteger')]
        public int $releaseGeneration = 0,
        #[Column(type: 'bigInteger')]
        public int $memoryRevision = 0,
        #[Column(type: 'boolean')]
        public bool $dreamEnabled = true,
        #[Column(type: 'text')]
        public string $dreamTimeZone = 'Asia/Yekaterinburg',
        #[Column(type: 'bigInteger', nullable: true)]
        public ?int $lastDreamAt = null,
        #[Column(type: 'bigInteger')]
        public int $createdAt = 0,
        #[Column(type: 'bigInteger')]
        public int $updatedAt = 0,
    ) {
        $now             = time();
        $this->createdAt = $this->createdAt === 0 ? $now : $this->createdAt;
        $this->updatedAt = $this->updatedAt === 0 ? $now : $this->updatedAt;
    }
}
