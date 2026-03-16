<?php

declare(strict_types=1);

namespace Bot\Entity;

use Bot\Entity\UserMemory\UserMemoryRepository;
use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use Cycle\Annotated\Annotation\Table\Index;

#[Entity(repository: UserMemoryRepository::class)]
#[Index(['chatId', 'userIdentifier'])]
class UserMemory
{
    #[Column(type: 'primary')]
    public int $id;

    public function __construct(
        #[Column(type: 'bigInteger')]
        public int $chatId,

        #[Column(type: 'text')]
        public string $userIdentifier,

        #[Column(type: 'text')]
        public string $category,

        #[Column(type: 'text')]
        public string $content,

        #[Column(type: 'bigInteger')]
        public int $createdAt,

        #[Column(type: 'bigInteger')]
        public int $updatedAt,
    ) {}
}
