<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

use Bot\Entity\ToolExecutionRecord;
use Bot\Entity\ToolExecutionRecord\ToolExecutionRecordRepository;
use Bot\Llm\Tools\Telegram\TelegramApiCallExecutor;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\RepositoryInterface;
use PiPHP\Temporal\Contract\ToolExecutionGatewayInterface;
use PiPHP\Temporal\DTO\ToolActivityInput;
use PiPHP\Temporal\DTO\ToolActivityResult;
use Throwable;
use UnexpectedValueException;

/**
 * Provides at-most-once retry handling for tools with external side effects.
 *
 * A claimed execution with no stored result has an inherently ambiguous
 * outcome. Re-running it could duplicate a Telegram message or notification,
 * so a retry returns an in-band terminal error instead.
 */
final readonly class IdempotentToolExecutionGateway implements ToolExecutionGatewayInterface
{
    private const string TERMINAL_OWNER_PREFIX  = '__terminal_owner__:';
    private const string TERMINAL_RECORD_PREFIX = 'terminal-action:';

    public function __construct(
        private ToolExecutionGatewayInterface $inner,
        private ORMInterface $orm,
    ) {}

    public function execute(ToolActivityInput $input): ToolActivityResult
    {
        if (str_starts_with($input->idempotencyKey, self::TERMINAL_RECORD_PREFIX)) {
            throw new UnexpectedValueException(
                'Tool idempotency keys cannot use the terminal ledger namespace.',
            );
        }

        $hasSideEffects  = self::hasSideEffects($input);
        $terminalScopeId = self::terminalScopeId($input);

        if (!$hasSideEffects && $terminalScopeId === null) {
            return $this->inner->execute($input);
        }

        /** @var ToolExecutionRecordRepository $repository */
        $repository = $this->orm->getRepository(ToolExecutionRecord::class);
        if ($hasSideEffects) {
            $record = $repository->findByIdempotencyKey($input->idempotencyKey);

            if ($record !== null) {
                if ($record->resultJson === null && self::isReplaySafeMutation($input)) {
                    self::assertExecutionIdentity($input, $record);

                    return $this->replayAppendOnlyMemoryMutation($repository, $input, $record);
                }

                return $this->existingExecutionResult(
                    $repository,
                    $input,
                    $record,
                    $terminalScopeId,
                );
            }
        }

        $terminalRecord = null;
        if ($terminalScopeId !== null) {
            $reservation = $this->reserveTerminalAction(
                $repository,
                $input,
                $terminalScopeId,
            );
            if ($reservation instanceof ToolActivityResult) {
                return $reservation;
            }

            $terminalRecord = $reservation;
        }

        if (!$hasSideEffects) {
            $result = $this->inner->execute($input);
            if ($terminalRecord !== null) {
                $this->completeTerminalAction($repository, $terminalRecord, $result);
            }

            return $result;
        }

        $record = new ToolExecutionRecord(
            idempotencyKey: $input->idempotencyKey,
            toolName: self::executionIdentity($input),
        );

        try {
            $repository->save($record);
        } catch (Throwable $error) {
            $record = $repository->findByIdempotencyKey($input->idempotencyKey);
            if ($record === null) {
                if ($terminalRecord !== null) {
                    $this->releaseTerminalAction($repository, $terminalRecord);
                }

                throw $error;
            }

            try {
                self::assertExecutionIdentity($input, $record);
            } catch (Throwable $identityError) {
                if ($terminalRecord !== null) {
                    $this->releaseTerminalAction($repository, $terminalRecord);
                }

                throw $identityError;
            }

            return $this->existingExecutionResult(
                $repository,
                $input,
                $record,
                $terminalScopeId,
            );
        }

        $result              = $this->inner->execute($input);
        $record->resultJson  = self::encode($result);
        $record->completedAt = time();
        $repository->save($record);
        if ($terminalRecord !== null) {
            $this->completeTerminalAction($repository, $terminalRecord, $result);
        }

        return $result;
    }

    private static function hasSideEffects(ToolActivityInput $input): bool
    {
        if ($input->name === 'telegram_api_call') {
            $method = $input->arguments['method'] ?? null;

            return !is_string($method) || !TelegramApiCallExecutor::isReadOnlyMethod($method);
        }

        return in_array($input->name, [
            'save_memory',
            'update_memory',
            'forget_memory',
            'upsert_runtime_skill',
            'upsert_runtime_tool',
            'set_runtime_capability_status',
            'publish_space_capability',
        ], true);
    }

    private static function isReplaySafeMutation(ToolActivityInput $input): bool
    {
        return in_array($input->name, [
            'save_memory',
            'update_memory',
            'forget_memory',
            'publish_space_capability',
        ], true);
    }

    private static function terminalScopeId(ToolActivityInput $input): ?string
    {
        if (!self::isTerminalAction($input)) {
            return null;
        }

        $scope = $input->metadata['terminalScopeId'] ?? null;
        if (is_string($scope) && $scope !== '') {
            return $scope;
        }

        $workflowId = $input->metadata['workflowId'] ?? null;
        $turn       = $input->metadata['turn'] ?? null;
        if (!is_string($workflowId) || $workflowId === '' || !is_int($turn)) {
            return null;
        }

        return "{$workflowId}:turn:{$turn}";
    }

    private static function isTerminalAction(ToolActivityInput $input): bool
    {
        if ($input->name === 'stay_silent') {
            return true;
        }

        $method = $input->arguments['method'] ?? null;

        return $input->name === 'telegram_api_call'
            && is_string($method)
            && TelegramApiCallExecutor::isTerminalMethod($method);
    }

    private static function terminalRecordKey(string $scopeId, int $generation): string
    {
        return self::TERMINAL_RECORD_PREFIX
            . hash('sha256', $scopeId)
            . ":{$generation}";
    }

    private static function terminalResultState(ToolActivityResult $result): string
    {
        return $result->terminate && !$result->isError
            ? 'completed'
            : 'released';
    }

    private static function terminalOwner(ToolExecutionRecord $record): string
    {
        if (!str_starts_with($record->toolName, self::TERMINAL_OWNER_PREFIX)) {
            throw new UnexpectedValueException('A terminal action ledger record has an invalid owner.');
        }

        return substr($record->toolName, strlen(self::TERMINAL_OWNER_PREFIX));
    }

    private static function terminalState(ToolExecutionRecord $record): string
    {
        if ($record->resultJson === null) {
            return 'claimed';
        }

        $data  = json_decode($record->resultJson, true, flags: \JSON_THROW_ON_ERROR);
        $state = is_array($data) ? ($data['status'] ?? null) : null;
        if (!in_array($state, ['completed', 'released'], true)) {
            throw new UnexpectedValueException('A terminal action ledger record has an invalid state.');
        }

        return $state;
    }

    private static function executionIdentity(ToolActivityInput $input): string
    {
        $encoded = json_encode(
            self::canonicalize([
                'callId'    => $input->callId,
                'name'      => $input->name,
                'arguments' => $input->arguments,
                'metadata'  => $input->metadata,
            ]),
            \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION,
        );

        return "{$input->name}:" . hash('sha256', $encoded);
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

    private static function assertExecutionIdentity(
        ToolActivityInput $input,
        ToolExecutionRecord $record,
    ): void {
        if ($record->toolName !== self::executionIdentity($input)) {
            throw new UnexpectedValueException(
                'A durable tool idempotency key was reused for a different tool input.',
            );
        }
    }

    private static function encode(ToolActivityResult $result): string
    {
        return json_encode([
            'callId'     => $result->callId,
            'name'       => $result->name,
            'content'    => $result->content,
            'isError'    => $result->isError,
            'details'    => $result->details,
            'usage'      => $result->usage,
            'addedTools' => $result->addedTools,
            'terminate'  => $result->terminate,
            'metadata'   => $result->metadata,
        ], \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @param array<string, mixed> $data
     * @param string               $key
     */
    private static function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new UnexpectedValueException("Stored durable tool result field {$key} must be a string.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @param string               $key
     *
     * @return array<mixed>
     */
    private static function requiredArray(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            throw new UnexpectedValueException("Stored durable tool result field {$key} must be an array.");
        }

        return $value;
    }

    /**
     * Space memory and capability publication stores have their own
     * payload-checked durable idempotency. They are safe to re-enter after the
     * gateway reserved a key but crashed before recording its result.
     *
     * @param ToolExecutionRecordRepository&RepositoryInterface<ToolExecutionRecord> $repository
     * @param ToolActivityInput                                                      $input
     * @param ToolExecutionRecord                                                    $record
     */
    private function replayAppendOnlyMemoryMutation(
        RepositoryInterface $repository,
        ToolActivityInput $input,
        ToolExecutionRecord $record,
    ): ToolActivityResult {
        $result              = $this->inner->execute($input);
        $record->resultJson  = self::encode($result);
        $record->completedAt = time();
        $repository->save($record);

        return $result;
    }

    /**
     * @param ToolExecutionRecordRepository&RepositoryInterface<ToolExecutionRecord> $repository
     * @param ToolActivityInput                                                      $input
     * @param string                                                                 $scopeId
     */
    private function reserveTerminalAction(
        RepositoryInterface $repository,
        ToolActivityInput $input,
        string $scopeId,
    ): ToolActivityResult|ToolExecutionRecord {
        for ($generation = 0;; ++$generation) {
            $recordKey = self::terminalRecordKey($scopeId, $generation);
            $record    = $repository->findByIdempotencyKey($recordKey);

            if ($record === null) {
                $record = new ToolExecutionRecord(
                    idempotencyKey: $recordKey,
                    toolName: self::TERMINAL_OWNER_PREFIX . $input->idempotencyKey,
                );

                try {
                    $repository->save($record);

                    return $record;
                } catch (Throwable $error) {
                    $record = $repository->findByIdempotencyKey($recordKey);
                    if ($record === null) {
                        throw $error;
                    }
                }
            }

            $owner = self::terminalOwner($record);
            $state = self::terminalState($record);
            if ($state === 'released') {
                continue;
            }

            return new ToolActivityResult(
                callId: $input->callId,
                name: $input->name,
                content: [[
                    'type' => 'text',
                    'text' => $owner === $input->idempotencyKey
                        ? 'This batch terminal action was already claimed by this tool call.'
                        : 'A different terminal action already won this batch; this action was not executed.',
                ]],
                isError: true,
                terminate: true,
                metadata: [
                    ...$input->metadata,
                    'idempotencyKey'           => $input->idempotencyKey,
                    'terminalActionSuppressed' => true,
                    'terminalActionState'      => $state,
                ],
            );
        }
    }

    /**
     * @param ToolExecutionRecordRepository&RepositoryInterface<ToolExecutionRecord> $repository
     * @param ToolExecutionRecord                                                    $record
     * @param ToolActivityResult                                                     $result
     */
    private function completeTerminalAction(
        RepositoryInterface $repository,
        ToolExecutionRecord $record,
        ToolActivityResult $result,
    ): void {
        $state = self::terminalResultState($result);
        $this->persistTerminalState($repository, $record, $state);
    }

    /**
     * @param ToolExecutionRecordRepository&RepositoryInterface<ToolExecutionRecord> $repository
     * @param ToolExecutionRecord                                                    $record
     */
    private function releaseTerminalAction(
        RepositoryInterface $repository,
        ToolExecutionRecord $record,
    ): void {
        $this->persistTerminalState($repository, $record, 'released');
    }

    /**
     * @param ToolExecutionRecordRepository&RepositoryInterface<ToolExecutionRecord> $repository
     * @param ToolExecutionRecord                                                    $record
     * @param string                                                                 $state
     */
    private function persistTerminalState(
        RepositoryInterface $repository,
        ToolExecutionRecord $record,
        string $state,
    ): void {
        $record->resultJson = json_encode(
            ['status' => $state],
            \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION,
        );
        $record->completedAt = time();
        $repository->save($record);
    }

    /**
     * @param ToolExecutionRecordRepository&RepositoryInterface<ToolExecutionRecord> $repository
     * @param ToolActivityInput                                                      $input
     * @param ToolExecutionRecord                                                    $record
     * @param ?string                                                                $terminalScopeId
     */
    private function existingExecutionResult(
        RepositoryInterface $repository,
        ToolActivityInput $input,
        ToolExecutionRecord $record,
        ?string $terminalScopeId,
    ): ToolActivityResult {
        $result = $this->storedOrAmbiguous($input, $record);
        if ($terminalScopeId !== null && $record->resultJson !== null) {
            $this->reconcileTerminalAction(
                $repository,
                $input,
                $terminalScopeId,
                $result,
            );
        }

        return $result;
    }

    /**
     * Repairs the safe partial-commit case where the side-effect result was
     * stored but the corresponding terminal state update failed.
     *
     * @param ToolExecutionRecordRepository&RepositoryInterface<ToolExecutionRecord> $repository
     * @param ToolActivityInput                                                      $input
     * @param string                                                                 $scopeId
     * @param ToolActivityResult                                                     $result
     */
    private function reconcileTerminalAction(
        RepositoryInterface $repository,
        ToolActivityInput $input,
        string $scopeId,
        ToolActivityResult $result,
    ): void {
        $expectedState = self::terminalResultState($result);
        for ($generation = 0;; ++$generation) {
            $record = $repository->findByIdempotencyKey(
                self::terminalRecordKey($scopeId, $generation),
            );
            if ($record === null) {
                throw new UnexpectedValueException(
                    'A completed terminal tool execution has no terminal ledger reservation.',
                );
            }

            $owner = self::terminalOwner($record);
            $state = self::terminalState($record);
            if ($owner === $input->idempotencyKey) {
                if ($state === 'claimed') {
                    $this->persistTerminalState($repository, $record, $expectedState);
                } elseif ($state !== $expectedState) {
                    throw new UnexpectedValueException(
                        'A terminal tool result conflicts with its durable ledger state.',
                    );
                }

                return;
            }

            if ($state !== 'released') {
                throw new UnexpectedValueException(
                    'A completed terminal tool execution is not owned by the terminal ledger.',
                );
            }
        }
    }

    private function storedOrAmbiguous(
        ToolActivityInput $input,
        ToolExecutionRecord $record,
    ): ToolActivityResult {
        self::assertExecutionIdentity($input, $record);

        if ($record->resultJson === null) {
            return new ToolActivityResult(
                callId: $input->callId,
                name: $input->name,
                content: [[
                    'type' => 'text',
                    'text' => 'This side-effecting tool was already attempted, but its outcome is unknown. '
                        . 'It was not repeated to avoid a duplicate external action.',
                ]],
                isError: true,
                terminate: true,
                metadata: [
                    ...$input->metadata,
                    'idempotencyKey'        => $input->idempotencyKey,
                    'ambiguousPriorAttempt' => true,
                ],
            );
        }

        $data = json_decode($record->resultJson, true, flags: \JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new UnexpectedValueException('Stored durable tool result must be a JSON object.');
        }

        return new ToolActivityResult(
            callId: self::requiredString($data, 'callId'),
            name: self::requiredString($data, 'name'),
            content: self::requiredArray($data, 'content'),
            isError: (bool) ($data['isError'] ?? false),
            details: $data['details'] ?? null,
            usage: self::requiredArray($data, 'usage'),
            addedTools: self::requiredArray($data, 'addedTools'),
            terminate: (bool) ($data['terminate'] ?? false),
            metadata: self::requiredArray($data, 'metadata'),
        );
    }
}
