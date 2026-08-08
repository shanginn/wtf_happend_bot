<?php

declare(strict_types=1);

namespace Bot\Durability;

final readonly class IdempotencyClaim
{
    /**
     * @param array<string, mixed>|null $result
     * @param string                    $idempotencyKey
     * @param string                    $identity
     * @param bool                      $acquired
     */
    public function __construct(
        public string $idempotencyKey,
        public string $identity,
        public bool $acquired,
        public ?array $result,
    ) {}
}
