<?php

declare(strict_types=1);

namespace Bot\Entity;

use Bot\Entity\ToolExecutionRecord\ToolExecutionRecordRepository;
use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use Cycle\Annotated\Annotation\Table\Index;

#[Entity(repository: ToolExecutionRecordRepository::class)]
#[Index(['idempotencyKey'], unique: true)]
#[Index(['completedAt'])]
class ToolExecutionRecord
{
    #[Column(type: 'primary')]
    public int $id;

    public function __construct(
        #[Column(type: 'text')]
        public string $idempotencyKey,
        #[Column(type: 'text')]
        public string $toolName,
        #[Column(type: 'text', nullable: true)]
        public ?string $resultJson = null,
        #[Column(type: 'bigInteger')]
        public int $createdAt = 0,
        #[Column(type: 'bigInteger', nullable: true)]
        public ?int $completedAt = null,
    ) {
        $this->createdAt = $this->createdAt === 0 ? time() : $this->createdAt;
    }
}
