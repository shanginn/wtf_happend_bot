<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

use Shanginn\Openai\ChatCompletion\Message\MessageInterface;
use Temporal\Internal\Marshaller\Meta\MarshalArray;

class WorkingMemory
{
    private const int LIMIT = 200;

    public function __construct(
        #[MarshalArray(of: MessageInterface::class)]
        private array $memories = [],
    )
    {
    }

    public function add(MessageInterface $message): void
    {
        if (count($this->memories) >= self::LIMIT) {
            array_shift($this->memories);
        }

        $this->memories[] = $message;
    }

    /**
     * @return MessageInterface[]
     */
    public function get(): array
    {
        return $this->memories;
    }
}