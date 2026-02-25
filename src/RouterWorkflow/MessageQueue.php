<?php

declare(strict_types=1);

namespace Bot\RouterWorkflow;

class MessageQueue
{
    private array $queue = [];

    public function push(mixed $item): void
    {
        $this->queue[] = $item;
    }

    public function has(): bool
    {
        return count($this->queue) > 0;
    }

    public function shift(): mixed
    {
        return array_shift($this->queue);
    }

    public function flush(): array
    {
        $items = $this->queue;
        $this->queue = [];
        return $items;
    }

    public function count(): int
    {
        return count($this->queue);
    }

    public function all(): array
    {
        return $this->queue;
    }
}
