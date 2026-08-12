<?php

declare(strict_types=1);

namespace Bot\Space\Workflow;

final class SpaceMessageQueue
{
    /** @var list<mixed> */
    private array $queue = [];

    public function push(mixed $item): void
    {
        $this->queue[] = $item;
    }

    /** @param list<mixed> $items */
    public function prepend(array $items): void
    {
        $this->queue = [...$items, ...$this->queue];
    }

    public function has(): bool
    {
        return $this->queue !== [];
    }

    /** @return list<mixed> */
    public function flush(): array
    {
        $items       = $this->queue;
        $this->queue = [];

        return $items;
    }

    /** @return list<mixed> */
    public function all(): array
    {
        return $this->queue;
    }
}
