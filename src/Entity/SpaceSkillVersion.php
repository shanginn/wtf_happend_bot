<?php

declare(strict_types=1);

namespace Bot\Entity;

use Bot\Entity\Space\SpaceSkillVersionRepository;
use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use Cycle\Annotated\Annotation\ForeignKey;
use Cycle\Annotated\Annotation\Table\Index;

#[Entity(repository: SpaceSkillVersionRepository::class, table: 'space_skill_versions')]
#[ForeignKey(target: Space::class, innerKey: 'spaceId', action: 'NO ACTION', indexCreate: false)]
#[ForeignKey(target: SpaceRelease::class, innerKey: 'releaseId', action: 'NO ACTION', indexCreate: false)]
#[Index(['releaseId', 'name'], unique: true)]
#[Index(['spaceId', 'name', 'version'], unique: true)]
#[Index(['releaseId', 'enabled'])]
class SpaceSkillVersion
{
    public function __construct(
        #[Column(type: 'text', primary: true)]
        public string $id,
        #[Column(type: 'text')]
        public string $spaceId,
        #[Column(type: 'text')]
        public string $releaseId,
        #[Column(type: 'text')]
        public string $name,
        #[Column(type: 'bigInteger')]
        public int $version,
        #[Column(type: 'text')]
        public string $description,
        #[Column(type: 'text')]
        public string $body,
        #[Column(type: 'text')]
        public string $manifestJson = '{}',
        #[Column(type: 'text', nullable: true)]
        public ?string $sourceDigest = null,
        #[Column(type: 'boolean')]
        public bool $enabled = true,
        #[Column(type: 'bigInteger')]
        public int $createdAt = 0,
    ) {
        $this->createdAt = $this->createdAt === 0 ? time() : $this->createdAt;
    }
}
