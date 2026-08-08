<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

class MessageQueue
{
    private array $queue = [];

    public function push(mixed $item): void
    {
        $this->queue[] = $item;
    }

    /**
     * @param list<mixed> $items
     */
    public function prepend(array $items): void
    {
        $this->queue = [...$items, ...$this->queue];
    }

    public function has(): bool
    {
        return count($this->queue) > 0;
    }

    public function flush(): array
    {
        $items       = $this->queue;
        $this->queue = [];

        return $items;
    }

    public function all(): array
    {
        return $this->queue;
    }
}
