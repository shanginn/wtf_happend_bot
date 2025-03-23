<?php

declare(strict_types=1);

namespace Bot\Entity;

use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;

#[Entity]
class SummarizationState
{
    #[Column(type: 'primary')]
    public int $id;

    public function __construct(
        #[Column(type: 'bigInteger')]
        public int $chatId,

        #[Column(type: 'bigInteger')]
        public int $lastSummarizedMessageId,
    ) {}
}