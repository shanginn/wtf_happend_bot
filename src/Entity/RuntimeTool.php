<?php

declare(strict_types=1);

namespace Bot\Entity;

use Bot\Entity\RuntimeTool\RuntimeToolRepository;
use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use Cycle\Annotated\Annotation\Table\Index;

#[Entity(repository: RuntimeToolRepository::class)]
#[Index(['chatId', 'name'], unique: true)]
#[Index(['chatId', 'enabled'])]
class RuntimeTool
{
    #[Column(type: 'primary')]
    public int $id;

    public function __construct(
        #[Column(type: 'bigInteger')]
        public int $chatId,

        #[Column(type: 'text')]
        public string $name,

        #[Column(type: 'text')]
        public string $description,

        #[Column(type: 'text')]
        public string $parametersSchema,

        #[Column(type: 'text')]
        public string $instructions,

        #[Column(type: 'boolean')]
        public bool $enabled = true,

        #[Column(type: 'bigInteger')]
        public int $createdAt = 0,

        #[Column(type: 'bigInteger')]
        public int $updatedAt = 0,
    ) {
        $now = time();
        $this->createdAt = $this->createdAt === 0 ? $now : $this->createdAt;
        $this->updatedAt = $this->updatedAt === 0 ? $now : $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = time();
    }
}
