<?php

declare(strict_types=1);

namespace Bot\Durability;

interface IdempotencyLedgerInterface
{
    public function claim(string $idempotencyKey, string $identity): IdempotencyClaim;

    /**
     * @param array<string, mixed> $result
     * @param IdempotencyClaim     $claim
     */
    public function complete(IdempotencyClaim $claim, array $result): void;
}
