<?php

declare(strict_types=1);

namespace Tests\AgenticWorkflow;

use Bot\AgenticWorkflow\IdempotentToolExecutionGateway;
use Bot\Entity\ToolExecutionRecord;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\RepositoryInterface;
use PiPHP\Temporal\Contract\ToolExecutionGatewayInterface;
use PiPHP\Temporal\DTO\ToolActivityInput;
use PiPHP\Temporal\DTO\ToolActivityResult;
use RuntimeException;
use Tests\TestCase;
use UnexpectedValueException;

final class IdempotentToolExecutionGatewayTest extends TestCase
{
    public function testSideEffectingToolReturnsCachedResultWithoutRepeatingInnerExecution(): void
    {
        $repository = new InMemoryToolExecutionRecordRepository();
        $inner = new RecordingToolExecutionGateway(new ToolActivityResult(
            callId: 'call-1',
            name: 'telegram_api_call',
            content: [['type' => 'text', 'text' => 'sent']],
            details: ['messageId' => 42],
            usage: ['requests' => 1],
            terminate: true,
            metadata: ['provider' => 'telegram'],
        ));
        $gateway = new IdempotentToolExecutionGateway(
            $inner,
            $this->ormReturning($repository),
        );
        $input = new ToolActivityInput(
            callId: 'call-1',
            name: 'telegram_api_call',
            arguments: ['method' => 'sendMessage'],
            idempotencyKey: 'workflow/run/tool/call-1',
            metadata: ['chatId' => -100123],
        );

        $first = $gateway->execute($input);
        $retry = $gateway->execute($input);

        self::assertSame(1, $inner->calls);
        self::assertSame($first->callId, $retry->callId);
        self::assertSame($first->content, $retry->content);
        self::assertSame($first->details, $retry->details);
        self::assertSame($first->usage, $retry->usage);
        self::assertSame($first->terminate, $retry->terminate);
        self::assertSame($first->metadata, $retry->metadata);
        self::assertNotNull($repository->findByIdempotencyKey($input->idempotencyKey)?->completedAt);
    }

    public function testReadOnlyToolBypassesLedger(): void
    {
        $inner = new RecordingToolExecutionGateway(new ToolActivityResult(
            callId: 'call-time',
            name: 'get_current_time',
            content: [['type' => 'text', 'text' => '2026-08-05T12:00:00+05:00']],
        ));
        $orm = $this->createMock(ORMInterface::class);
        $orm->expects($this->never())->method('getRepository');
        $gateway = new IdempotentToolExecutionGateway($inner, $orm);
        $input = new ToolActivityInput(
            callId: 'call-time',
            name: 'get_current_time',
            arguments: ['timezone' => 'Asia/Yekaterinburg'],
            idempotencyKey: 'workflow/run/tool/call-time',
        );

        $gateway->execute($input);
        $gateway->execute($input);

        self::assertSame(2, $inner->calls);
    }

    public function testReadOnlyTelegramMethodBypassesLedger(): void
    {
        $inner = new RecordingToolExecutionGateway(new ToolActivityResult(
            callId: 'call-chat',
            name: 'telegram_api_call',
            content: [['type' => 'text', 'text' => 'chat metadata']],
        ));
        $orm = $this->createMock(ORMInterface::class);
        $orm->expects($this->never())->method('getRepository');
        $gateway = new IdempotentToolExecutionGateway($inner, $orm);
        $input = new ToolActivityInput(
            callId: 'call-chat',
            name: 'telegram_api_call',
            arguments: ['method' => 'getChat'],
            idempotencyKey: 'workflow/run/tool/call-chat',
        );

        $gateway->execute($input);
        $gateway->execute($input);

        self::assertSame(2, $inner->calls);
    }

    public function testOnlyOneTerminalTelegramActionExecutesPerLogicalBatch(): void
    {
        $repository = new InMemoryToolExecutionRecordRepository();
        $inner = new RecordingToolExecutionGateway(new ToolActivityResult(
            callId: 'call-terminal',
            name: 'telegram_api_call',
            content: [['type' => 'text', 'text' => 'sent']],
            terminate: true,
        ));
        $gateway = new IdempotentToolExecutionGateway(
            $inner,
            $this->ormReturning($repository),
        );

        $first = new ToolActivityInput(
            callId: 'call-1',
            name: 'telegram_api_call',
            arguments: ['method' => 'sendMessage'],
            idempotencyKey: 'workflow/run/tool/call-1',
            metadata: ['terminalScopeId' => 'batch-1'],
        );
        $second = new ToolActivityInput(
            callId: 'call-2',
            name: 'telegram_api_call',
            arguments: ['method' => 'sendPhoto'],
            idempotencyKey: 'workflow/run/tool/call-2',
            metadata: ['terminalScopeId' => 'batch-1'],
        );
        $nextBatch = new ToolActivityInput(
            callId: 'call-3',
            name: 'telegram_api_call',
            arguments: ['method' => 'sendMessage'],
            idempotencyKey: 'workflow/run/tool/call-3',
            metadata: ['terminalScopeId' => 'batch-2'],
        );

        $gateway->execute($first);
        $suppressed = $gateway->execute($second);
        $gateway->execute($nextBatch);

        self::assertSame(2, $inner->calls);
        self::assertTrue($suppressed->isError);
        self::assertTrue($suppressed->terminate);
        self::assertTrue($suppressed->metadata['terminalActionSuppressed']);
        self::assertSame('completed', $suppressed->metadata['terminalActionState']);
    }

    public function testClaimedExecutionWithoutResultReturnsTerminalAmbiguity(): void
    {
        $input = new ToolActivityInput(
            callId: 'call-ambiguous',
            name: 'save_memory',
            arguments: ['memory' => 'Durable fact'],
            idempotencyKey: 'workflow/run/tool/call-ambiguous',
            metadata: ['chatId' => -100123],
        );
        $repository = new InMemoryToolExecutionRecordRepository();
        $claimingGateway = new IdempotentToolExecutionGateway(
            new ThrowingToolExecutionGateway(new RuntimeException('outcome unavailable')),
            $this->ormReturning($repository),
        );
        try {
            $claimingGateway->execute($input);
            self::fail('The simulated side effect must lose its outcome.');
        } catch (RuntimeException $failure) {
            self::assertSame('outcome unavailable', $failure->getMessage());
        }
        $inner = new RecordingToolExecutionGateway(new ToolActivityResult(
            callId: $input->callId,
            name: $input->name,
            content: [['type' => 'text', 'text' => 'must not execute']],
        ));
        $gateway = new IdempotentToolExecutionGateway(
            $inner,
            $this->ormReturning($repository),
        );

        $result = $gateway->execute($input);

        self::assertSame(0, $inner->calls);
        self::assertTrue($result->isError);
        self::assertTrue($result->terminate);
        self::assertStringContainsString('outcome is unknown', $result->content[0]['text']);
        self::assertSame(-100123, $result->metadata['chatId']);
        self::assertSame($input->idempotencyKey, $result->metadata['idempotencyKey']);
        self::assertTrue($result->metadata['ambiguousPriorAttempt']);
    }

    public function testTerminalReservationIsReleasedWhenSideEffectClaimDefinitelyFails(): void
    {
        $repository = new InMemoryToolExecutionRecordRepository();
        $inner = new RecordingToolExecutionGateway(new ToolActivityResult(
            callId: 'call-claim',
            name: 'telegram_api_call',
            content: [['type' => 'text', 'text' => 'sent']],
            terminate: true,
        ));
        $gateway = new IdempotentToolExecutionGateway(
            $inner,
            $this->ormReturning($repository),
        );
        $input = new ToolActivityInput(
            callId: 'call-claim',
            name: 'telegram_api_call',
            arguments: ['method' => 'sendMessage'],
            idempotencyKey: 'workflow/run/tool/call-claim',
            metadata: ['terminalScopeId' => 'claim-failure-batch'],
        );
        $failClaim = true;
        $repository->beforeSave = static function (
            ToolExecutionRecord $record,
        ) use (&$failClaim, $input): void {
            if ($failClaim && $record->idempotencyKey === $input->idempotencyKey) {
                $failClaim = false;
                throw new RuntimeException('side-effect claim unavailable');
            }
        };

        try {
            $gateway->execute($input);
            self::fail('The first idempotency claim must fail.');
        } catch (RuntimeException $failure) {
            self::assertSame('side-effect claim unavailable', $failure->getMessage());
        }

        self::assertSame(0, $inner->calls);
        self::assertSame(
            ['released'],
            array_map(self::terminalState(...), $repository->terminalRecords()),
        );

        $result = $gateway->execute($input);

        self::assertFalse($result->isError);
        self::assertSame(1, $inner->calls);
        self::assertSame(
            ['released', 'completed'],
            array_map(self::terminalState(...), $repository->terminalRecords()),
        );
    }

    public function testCachedSideEffectResultRepairsFailedTerminalCompletionWrite(): void
    {
        $repository = new InMemoryToolExecutionRecordRepository();
        $inner = new RecordingToolExecutionGateway(new ToolActivityResult(
            callId: 'call-completion',
            name: 'telegram_api_call',
            content: [['type' => 'text', 'text' => 'sent']],
            terminate: true,
        ));
        $gateway = new IdempotentToolExecutionGateway(
            $inner,
            $this->ormReturning($repository),
        );
        $input = new ToolActivityInput(
            callId: 'call-completion',
            name: 'telegram_api_call',
            arguments: ['method' => 'sendMessage'],
            idempotencyKey: 'workflow/run/tool/call-completion',
            metadata: ['terminalScopeId' => 'completion-failure-batch'],
        );
        $failCompletion = true;
        $repository->beforeSave = static function (
            ToolExecutionRecord $record,
        ) use (&$failCompletion): void {
            if (
                $failCompletion
                && str_starts_with($record->idempotencyKey, 'terminal-action:')
                && self::terminalState($record) === 'completed'
            ) {
                $failCompletion = false;
                throw new RuntimeException('terminal completion unavailable');
            }
        };

        try {
            $gateway->execute($input);
            self::fail('The simulated terminal completion write must fail.');
        } catch (RuntimeException $failure) {
            self::assertSame('terminal completion unavailable', $failure->getMessage());
        }

        self::assertSame(1, $inner->calls);
        self::assertSame('claimed', self::terminalState($repository->terminalRecords()[0]));

        $retry = $gateway->execute($input);

        self::assertFalse($retry->isError);
        self::assertSame(1, $inner->calls);
        self::assertSame('completed', self::terminalState($repository->terminalRecords()[0]));
    }

    public function testReleasedTerminalReservationUsesANewImmutableGeneration(): void
    {
        $repository = new InMemoryToolExecutionRecordRepository();
        $inner = new RecordingToolExecutionGateway(
            static fn(ToolActivityInput $input): ToolActivityResult => new ToolActivityResult(
                callId: $input->callId,
                name: $input->name,
                content: [['type' => 'text', 'text' => $input->callId]],
                isError: $input->callId === 'call-released',
                terminate: $input->callId !== 'call-released',
            ),
        );
        $gateway = new IdempotentToolExecutionGateway(
            $inner,
            $this->ormReturning($repository),
        );
        $released = self::terminalInput('call-released', 'released-key', 'generation-batch');
        $winner = self::terminalInput('call-winner', 'winner-key', 'generation-batch');
        $suppressed = self::terminalInput('call-suppressed', 'suppressed-key', 'generation-batch');

        $gateway->execute($released);
        $gateway->execute($winner);
        $suppressedResult = $gateway->execute($suppressed);

        $terminalRecords = $repository->terminalRecords();
        self::assertSame(2, $inner->calls);
        self::assertSame(['released', 'completed'], array_map(self::terminalState(...), $terminalRecords));
        self::assertStringEndsWith(':0', $terminalRecords[0]->idempotencyKey);
        self::assertStringEndsWith(':1', $terminalRecords[1]->idempotencyKey);
        self::assertStringEndsWith($released->idempotencyKey, $terminalRecords[0]->toolName);
        self::assertStringEndsWith($winner->idempotencyKey, $terminalRecords[1]->toolName);
        self::assertTrue($suppressedResult->metadata['terminalActionSuppressed']);
        self::assertSame('completed', $suppressedResult->metadata['terminalActionState']);
    }

    public function testConcurrentCallsAfterReleaseHaveOneGenerationWinner(): void
    {
        $repository = new InMemoryToolExecutionRecordRepository();
        $inner = new RecordingToolExecutionGateway(
            static fn(ToolActivityInput $input): ToolActivityResult => new ToolActivityResult(
                callId: $input->callId,
                name: $input->name,
                content: [['type' => 'text', 'text' => $input->callId]],
                isError: $input->callId === 'call-released',
                terminate: $input->callId !== 'call-released',
            ),
        );
        $gateway = new IdempotentToolExecutionGateway(
            $inner,
            $this->ormReturning($repository),
        );
        $gateway->execute(self::terminalInput(
            'call-released',
            'released-key',
            'concurrent-batch',
        ));
        $outer = self::terminalInput('call-outer', 'outer-key', 'concurrent-batch');
        $nested = self::terminalInput('call-nested', 'nested-key', 'concurrent-batch');
        $nestedResult = null;
        $interleaved = false;
        $repository->beforeSave = function (
            ToolExecutionRecord $record,
        ) use (&$interleaved, &$nestedResult, $gateway, $nested, $repository): void {
            if (
                !$interleaved
                && str_starts_with($record->idempotencyKey, 'terminal-action:')
                && str_ends_with($record->idempotencyKey, ':1')
                && !isset($record->id)
            ) {
                $interleaved = true;
                $repository->beforeSave = null;
                $nestedResult = $gateway->execute($nested);
            }
        };

        $outerResult = $gateway->execute($outer);

        self::assertInstanceOf(ToolActivityResult::class, $nestedResult);
        self::assertFalse($nestedResult->isError);
        self::assertSame(2, $inner->calls);
        self::assertTrue($outerResult->metadata['terminalActionSuppressed']);
        self::assertSame('completed', $outerResult->metadata['terminalActionState']);
        self::assertSame(
            ['released', 'completed'],
            array_map(self::terminalState(...), $repository->terminalRecords()),
        );
    }

    public function testDuplicateKeyIsBoundToCanonicalToolInput(): void
    {
        $repository = new InMemoryToolExecutionRecordRepository();
        $inner = new RecordingToolExecutionGateway(new ToolActivityResult(
            callId: 'call-duplicate',
            name: 'telegram_api_call',
            content: [['type' => 'text', 'text' => 'sent']],
        ));
        $gateway = new IdempotentToolExecutionGateway(
            $inner,
            $this->ormReturning($repository),
        );
        $first = new ToolActivityInput(
            callId: 'call-duplicate',
            name: 'telegram_api_call',
            arguments: [
                'method' => 'sendMessage',
                'parameters' => ['chat_id' => 1, 'text' => 'hello'],
            ],
            idempotencyKey: 'duplicate-key',
            metadata: ['topicId' => 2, 'chatId' => 1],
        );
        $equivalent = new ToolActivityInput(
            callId: 'call-duplicate',
            name: 'telegram_api_call',
            arguments: [
                'parameters' => ['text' => 'hello', 'chat_id' => 1],
                'method' => 'sendMessage',
            ],
            idempotencyKey: 'duplicate-key',
            metadata: ['chatId' => 1, 'topicId' => 2],
        );
        $changed = new ToolActivityInput(
            callId: 'call-duplicate',
            name: 'telegram_api_call',
            arguments: [
                'method' => 'sendMessage',
                'parameters' => ['chat_id' => 1, 'text' => 'different'],
            ],
            idempotencyKey: 'duplicate-key',
            metadata: ['chatId' => 1, 'topicId' => 2],
        );

        $gateway->execute($first);
        $gateway->execute($equivalent);

        try {
            $gateway->execute($changed);
            self::fail('A changed tool input must not reuse a cached idempotent result.');
        } catch (UnexpectedValueException $failure) {
            self::assertStringContainsString('different tool input', $failure->getMessage());
        }
        self::assertSame(1, $inner->calls);
    }

    private function ormReturning(RepositoryInterface $repository): ORMInterface
    {
        $orm = $this->createMock(ORMInterface::class);
        $orm
            ->method('getRepository')
            ->with(ToolExecutionRecord::class)
            ->willReturn($repository);

        return $orm;
    }

    private static function terminalInput(
        string $callId,
        string $idempotencyKey,
        string $scopeId,
    ): ToolActivityInput {
        return new ToolActivityInput(
            callId: $callId,
            name: 'telegram_api_call',
            arguments: ['method' => 'sendMessage'],
            idempotencyKey: $idempotencyKey,
            metadata: ['terminalScopeId' => $scopeId],
        );
    }

    private static function terminalState(ToolExecutionRecord $record): string
    {
        if ($record->resultJson === null) {
            return 'claimed';
        }

        $data = json_decode($record->resultJson, true, flags: \JSON_THROW_ON_ERROR);

        return is_array($data) && is_string($data['status'] ?? null)
            ? $data['status']
            : 'invalid';
    }
}

final class RecordingToolExecutionGateway implements ToolExecutionGatewayInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly ToolActivityResult|\Closure $result,
    ) {}

    public function execute(ToolActivityInput $input): ToolActivityResult
    {
        ++$this->calls;

        return $this->result instanceof \Closure
            ? ($this->result)($input)
            : $this->result;
    }
}

final readonly class ThrowingToolExecutionGateway implements ToolExecutionGatewayInterface
{
    public function __construct(
        private \Throwable $failure,
    ) {}

    public function execute(ToolActivityInput $input): ToolActivityResult
    {
        throw $this->failure;
    }
}

/**
 * @implements RepositoryInterface<ToolExecutionRecord>
 */
final class InMemoryToolExecutionRecordRepository implements RepositoryInterface
{
    /** @var array<string, ToolExecutionRecord> */
    private array $records = [];
    private int $nextId = 1;

    public ?\Closure $beforeSave = null;

    /**
     * @param list<ToolExecutionRecord> $records
     */
    public function __construct(array $records = [])
    {
        foreach ($records as $record) {
            $this->commit($record);
        }
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?ToolExecutionRecord
    {
        $record = $this->records[$idempotencyKey] ?? null;

        return $record === null ? null : clone $record;
    }

    public function save(ToolExecutionRecord $record, bool $run = true): void
    {
        if ($this->beforeSave !== null) {
            ($this->beforeSave)($record, $this);
        }

        $stored = $this->records[$record->idempotencyKey] ?? null;
        if ($stored !== null) {
            if (!isset($record->id) || $record->id !== $stored->id) {
                throw new RuntimeException('duplicate idempotency key');
            }
        } elseif (isset($record->id)) {
            throw new RuntimeException('cannot update a missing ledger record');
        } else {
            $record->id = $this->nextId++;
        }

        $this->records[$record->idempotencyKey] = clone $record;
    }

    public function findByPK(mixed $id): ?object
    {
        foreach ($this->records as $record) {
            if ($record->id === $id) {
                return clone $record;
            }
        }

        return null;
    }

    public function findOne(array $scope = []): ?object
    {
        return null;
    }

    public function findAll(array $scope = []): iterable
    {
        return array_map(
            static fn(ToolExecutionRecord $record): ToolExecutionRecord => clone $record,
            array_values($this->records),
        );
    }

    /**
     * @return list<ToolExecutionRecord>
     */
    public function terminalRecords(): array
    {
        return array_values(array_filter(
            $this->findAll(),
            static fn(ToolExecutionRecord $record): bool =>
                str_starts_with($record->idempotencyKey, 'terminal-action:'),
        ));
    }

    private function commit(ToolExecutionRecord $record): void
    {
        if (!isset($record->id)) {
            $record->id = $this->nextId++;
        } else {
            $this->nextId = max($this->nextId, $record->id + 1);
        }

        $this->records[$record->idempotencyKey] = clone $record;
    }
}
