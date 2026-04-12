<?php

declare(strict_types=1);

namespace Bot\Entity;

use Bot\Entity\LlmProviderResponse\LlmProviderResponseRepository;
use Bot\Llm\ProviderHistory\LlmProviderType;
use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use Cycle\Annotated\Annotation\Table\Index;

#[Entity(repository: LlmProviderResponseRepository::class)]
#[Index(['chatId', 'topicId', 'type'])]
class LlmProviderResponse
{
    #[Column(type: 'primary')]
    public int $id;

    public function __construct(
        #[Column(type: 'bigInteger')]
        public int $chatId,

        #[Column(type: 'bigInteger', nullable: true)]
        public ?int $topicId,

        #[Column(type: 'text', typecast: LlmProviderType::class)]
        public LlmProviderType $type,

        #[Column(type: 'text')]
        public string $messageClass,

        #[Column(type: 'text')]
        public string $payload,

        #[Column(type: 'text', nullable: true)]
        public ?string $rawResponse = null,

        #[Column(type: 'bigInteger')]
        public int $createdAt = 0,
    ) {
        $this->createdAt = $this->createdAt === 0 ? time() : $this->createdAt;
    }
}
