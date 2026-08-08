<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

use Bot\Entity\ModelCompletionRecord;
use Bot\Entity\ModelCompletionRecord\ModelCompletionRecordRepository;
use Bot\Infrastructure\CycleORM\CycleOrmScope;
use PiPHP\Temporal\Contract\ModelCompletionResultStoreInterface;
use PiPHP\Temporal\DTO\ModelActivityResult;
use Throwable;

final readonly class CycleModelCompletionResultStore implements ModelCompletionResultStoreInterface
{
    public function __construct(
        private CycleOrmScope $ormScope,
    ) {}

    public function load(string $idempotencyKey): ?ModelActivityResult
    {
        $record = $this->repository()->findByIdempotencyKey($idempotencyKey);

        return $record === null
            ? null
            : ModelCompletionRecordSerializer::decode($record->resultJson);
    }

    public function save(string $idempotencyKey, ModelActivityResult $result): void
    {
        $repository = $this->repository();
        if ($repository->findByIdempotencyKey($idempotencyKey) !== null) {
            return;
        }

        try {
            $repository->save(new ModelCompletionRecord(
                idempotencyKey: $idempotencyKey,
                resultJson: ModelCompletionRecordSerializer::encode($result),
            ));
        } catch (Throwable $error) {
            if ($repository->findByIdempotencyKey($idempotencyKey) === null) {
                throw $error;
            }
        }
    }

    private function repository(): ModelCompletionRecordRepository
    {
        /** @var ModelCompletionRecordRepository $repository */
        return $this->ormScope
            ->current()
            ->orm
            ->getRepository(ModelCompletionRecord::class);
    }
}
