<?php

declare(strict_types=1);

namespace Bot\Entity;

use Bot\Entity\ModelCompletionRecord\ModelCompletionRecordRepository;
use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use Cycle\Annotated\Annotation\Table\Index;

#[Entity(repository: ModelCompletionRecordRepository::class)]
#[Index(['idempotencyKey'], unique: true)]
#[Index(['createdAt'])]
class ModelCompletionRecord
{
    #[Column(type: 'primary')]
    public int $id;

    public function __construct(
        #[Column(type: 'text')]
        public string $idempotencyKey,
        #[Column(type: 'text')]
        public string $resultJson,
        #[Column(type: 'bigInteger')]
        public int $createdAt = 0,
    ) {
        $this->createdAt = $this->createdAt === 0 ? time() : $this->createdAt;
    }
}
