<?php

declare(strict_types=1);

namespace Bot\Entity;

use Bot\Entity\UpdateRecord\UpdateRecordRepository;
use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use Cycle\Annotated\Annotation\Table\Index;

#[Entity(repository: UpdateRecordRepository::class)]
#[Index(['chatId'])]
class UpdateRecord
{
    public function __construct(
        #[Column(type: 'bigInteger', primary: true)]
        public int $updateId,

        #[Column(type: 'text')]
        public string $update,

        #[Column(type: 'bigInteger')]
        public int $chatId,

        #[Column(type: 'bigInteger', nullable: true)]
        public ?int $topicId = null,

        #[Column(type: 'bigInteger')]
        public int $createdAt = 0,
    ) {
        $this->createdAt = $this->createdAt === 0 ? time() : $this->createdAt;
    }
}
