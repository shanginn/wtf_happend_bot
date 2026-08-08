<?php

declare(strict_types=1);

namespace Bot\Durability;

use Bot\Entity\ToolExecutionRecord;
use Bot\Entity\ToolExecutionRecord\ToolExecutionRecordRepository;
use Bot\Infrastructure\CycleORM\CycleOrmContext;
use Bot\Infrastructure\CycleORM\CycleOrmScope;
use Cycle\ORM\RepositoryInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Process-local entry point to the shared PostgreSQL idempotency ledger.
 *
 * Each operation uses the calling coroutine's isolated Cycle context. Unique
 * keys provide the claim primitive; result payloads make completed claims
 * immutable and reusable without coupling callers to PiPH activity DTOs.
 */
final readonly class CycleIdempotencyLedger implements IdempotencyLedgerInterface
{
    public function __construct(
        private CycleOrmScope $ormScope,
    ) {}

    public function claim(string $idempotencyKey, string $identity): IdempotencyClaim
    {
        $context      = $this->ormScope->current();
        $claimFailure = null;

        try {
            $repository = self::repository($context);
            $existing   = $repository->findByIdempotencyKey($idempotencyKey);
            if ($existing !== null) {
                return self::existingClaim($existing, $identity);
            }

            $record = new ToolExecutionRecord(
                idempotencyKey: $idempotencyKey,
                toolName: $identity,
            );

            try {
                $repository->save($record);
            } catch (Throwable $failure) {
                $claimFailure = $failure;
            }

            if ($claimFailure === null) {
                return new IdempotencyClaim(
                    idempotencyKey: $idempotencyKey,
                    identity: $identity,
                    acquired: true,
                    result: null,
                );
            }
        } finally {
            // A unique violation can leave the current unit of work or
            // transaction unusable. Always release it before race recovery.
            $this->ormScope->finalizeCurrent();
        }

        return $this->recoverCompetingClaim(
            $idempotencyKey,
            $identity,
            $claimFailure,
        );
    }

    public function complete(IdempotencyClaim $claim, array $result): void
    {
        if (!$claim->acquired) {
            throw new UnexpectedValueException('Only the owner of an idempotency claim can complete it.');
        }

        $context = $this->ormScope->current();

        try {
            $repository = self::repository($context);
            $record     = $repository->findByIdempotencyKey($claim->idempotencyKey);
            if ($record === null) {
                throw new UnexpectedValueException('The idempotency claim no longer exists.');
            }

            self::assertIdentity($record, $claim->identity);

            $encoded = self::encode($result);
            if ($record->resultJson !== null) {
                if ($record->resultJson !== $encoded) {
                    throw new UnexpectedValueException(
                        'An idempotency claim cannot be completed with a different result.',
                    );
                }

                return;
            }

            $record->resultJson  = $encoded;
            $record->completedAt = time();
            $repository->save($record);
        } finally {
            $this->ormScope->finalizeCurrent();
        }
    }

    /**
     * @param CycleOrmContext $context
     *
     * @return ToolExecutionRecordRepository&RepositoryInterface<ToolExecutionRecord>
     */
    private static function repository(CycleOrmContext $context): RepositoryInterface
    {
        /** @var ToolExecutionRecordRepository&RepositoryInterface<ToolExecutionRecord> $repository */
        return $context->orm->getRepository(ToolExecutionRecord::class);
    }

    private static function existingClaim(
        ToolExecutionRecord $record,
        string $identity,
    ): IdempotencyClaim {
        self::assertIdentity($record, $identity);

        return new IdempotencyClaim(
            idempotencyKey: $record->idempotencyKey,
            identity: $identity,
            acquired: false,
            result: self::decode($record->resultJson),
        );
    }

    private static function assertIdentity(ToolExecutionRecord $record, string $identity): void
    {
        if ($record->toolName !== $identity) {
            throw new UnexpectedValueException(
                'An idempotency key was reused for a different operation.',
            );
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function encode(array $result): string
    {
        return json_encode(
            self::canonicalize($result),
            \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /**
     * @param ?string $encoded
     *
     * @return array<string, mixed>|null
     */
    private static function decode(?string $encoded): ?array
    {
        if ($encoded === null) {
            return null;
        }

        $result = json_decode($encoded, true, flags: \JSON_THROW_ON_ERROR);
        if (!is_array($result) || array_is_list($result)) {
            throw new UnexpectedValueException('Stored idempotency result must be an object.');
        }

        return $result;
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value, \SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }

    private function recoverCompetingClaim(
        string $idempotencyKey,
        string $identity,
        Throwable $claimFailure,
    ): IdempotencyClaim {
        $context = $this->ormScope->current();

        try {
            $existing = self::repository($context)->findByIdempotencyKey($idempotencyKey);
            if ($existing === null) {
                throw $claimFailure;
            }

            return self::existingClaim($existing, $identity);
        } finally {
            $this->ormScope->finalizeCurrent();
        }
    }
}
