<?php

declare(strict_types=1);

namespace Bot\Entity;

use Bot\Entity\Space\SpaceBindingRepository;
use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use Cycle\Annotated\Annotation\ForeignKey;
use Cycle\Annotated\Annotation\Table\Index;

#[Entity(repository: SpaceBindingRepository::class, table: 'space_bindings')]
#[ForeignKey(target: Space::class, innerKey: 'spaceId', action: 'NO ACTION', indexCreate: false)]
#[Index(
    ['botInstanceId', 'platform', 'externalConversationId', 'externalThreadId'],
    unique: true,
)]
#[Index(['spaceId'])]
class SpaceBinding
{
    public function __construct(
        #[Column(type: 'text', primary: true)]
        public string $id,
        #[Column(type: 'text')]
        public string $spaceId,
        #[Column(type: 'text')]
        public string $botInstanceId,
        #[Column(type: 'text')]
        public string $platform,
        #[Column(type: 'text')]
        public string $externalConversationId,
        #[Column(type: 'text')]
        public string $externalThreadId = '',
        #[Column(type: 'bigInteger')]
        public int $createdAt = 0,
    ) {
        $this->createdAt = $this->createdAt === 0 ? time() : $this->createdAt;
    }
}
