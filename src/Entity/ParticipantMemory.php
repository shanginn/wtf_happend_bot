<?php

declare(strict_types=1);

namespace Bot\Entity;

use Bot\Entity\ParticipantMemory\ParticipantMemoryRepository;
use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use Cycle\Annotated\Annotation\Table\Index;

#[Entity(repository: ParticipantMemoryRepository::class)]
#[Index(['chatId', 'participantKey'])]
class ParticipantMemory
{
    #[Column(type: 'primary')]
    public int $id;

    public function __construct(
        #[Column(type: 'bigInteger')]
        public int $chatId,

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

        #[Column(type: 'bigInteger')]
        public ?int $createdAt = 0,

        #[Column(type: 'bigInteger')]
        public ?int $updatedAt = 0,
    ) {
        $now = time();
        $this->createdAt = $this->createdAt === 0 ? $now : $this->createdAt;
        $this->updatedAt = $this->updatedAt === 0 ? $now : $this->updatedAt;
    }
}
