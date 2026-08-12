<?php

declare(strict_types=1);

namespace Tests\Activity;

use Bot\Activity\TelegramActivity;
use Bot\Entity\UpdateRecord;
use Bot\Telegram\Update;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\RepositoryInterface;
use Cycle\ORM\Transaction\StateInterface;
use Phenogram\Bindings\Factories\ChatFactory;
use Phenogram\Bindings\Factories\MessageFactory;
use Phenogram\Bindings\Factories\UpdateFactory;
use Phenogram\Bindings\SerializerInterface;
use RuntimeException;
use Temporal\Exception\Failure\ApplicationFailure;
use Tests\TestCase;

final class TelegramActivitySaveUpdatesTest extends TestCase
{
    public function testNewRecordUsesTrustedWorkflowChatWhenUpdateHasNoEffectiveChat(): void
    {
        $repository    = new InMemoryUpdateRecordRepository();
        $persisted     = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects($this->once())
            ->method('persist')
            ->willReturnCallback(
                function (object $entity) use ($entityManager, $repository, &$persisted): EntityManagerInterface {
                    self::assertInstanceOf(UpdateRecord::class, $entity);
                    $persisted = $entity;
                    $repository->store($entity);

                    return $entityManager;
                },
            );
        $entityManager
            ->expects($this->once())
            ->method('run')
            ->willReturn($this->createStub(StateInterface::class));

        $saved = $this->activity($repository, $entityManager)->saveUpdates(
            new Update(updateId: 501),
            workflowChatId: -100123,
            ingestionRunId: 'workflow/run-1/ingestion-1',
        );

        self::assertTrue($saved);
        self::assertInstanceOf(UpdateRecord::class, $persisted);
        self::assertSame(501, $persisted->updateId);
        self::assertSame(-100123, $persisted->chatId);
        self::assertNull($persisted->topicId);
        self::assertSame('workflow/run-1/ingestion-1', $persisted->ingestionRunId);
    }

    public function testRetryOwnedBySameIngestionRunReturnsTrueWithoutPersistingAgain(): void
    {
        $repository = new InMemoryUpdateRecordRepository([
            new UpdateRecord(
                updateId: 502,
                update: '{"update_id":502}',
                chatId: -100123,
                ingestionRunId: 'workflow/run-1/ingestion-2',
            ),
        ]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');
        $entityManager->expects($this->never())->method('run');

        $saved = $this->activity($repository, $entityManager)->saveUpdates(
            new Update(updateId: 502),
            workflowChatId: -100123,
            ingestionRunId: 'workflow/run-1/ingestion-2',
        );

        self::assertTrue($saved);
    }

    public function testOnlyRealTelegramTopicsArePersistedAsTopics(): void
    {
        foreach ([
            'generic reply thread' => [506, 193132, null, null],
            'forum topic'          => [507, 42, true, 42],
        ] as $case => [$updateId, $messageThreadId, $isTopicMessage, $expectedTopicId]) {
            $repository    = new InMemoryUpdateRecordRepository();
            $persisted     = null;
            $entityManager = $this->createMock(EntityManagerInterface::class);
            $entityManager
                ->expects($this->once())
                ->method('persist')
                ->willReturnCallback(
                    function (object $entity) use ($entityManager, $repository, &$persisted): EntityManagerInterface {
                        self::assertInstanceOf(UpdateRecord::class, $entity);
                        $persisted = $entity;
                        $repository->store($entity);

                        return $entityManager;
                    },
                );
            $entityManager
                ->expects($this->once())
                ->method('run')
                ->willReturn($this->createStub(StateInterface::class));
            $update = UpdateFactory::make(
                updateId: $updateId,
                message: MessageFactory::make(
                    chat: ChatFactory::make(id: -100123, type: 'supergroup'),
                    messageThreadId: $messageThreadId,
                    isTopicMessage: $isTopicMessage,
                ),
            );
            self::assertInstanceOf(Update::class, $update);

            $this->activity($repository, $entityManager)->saveUpdates(
                $update,
                workflowChatId: -100123,
                ingestionRunId: "workflow/run-1/{$case}",
            );

            self::assertInstanceOf(UpdateRecord::class, $persisted);
            self::assertSame($expectedTopicId, $persisted->topicId, $case);
        }
    }

    public function testRecordOwnedByAnotherIngestionRunReturnsFalse(): void
    {
        $repository = new InMemoryUpdateRecordRepository([
            new UpdateRecord(
                updateId: 503,
                update: '{"update_id":503}',
                chatId: -100123,
                ingestionRunId: 'workflow/older-run/ingestion-1',
            ),
        ]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');
        $entityManager->expects($this->never())->method('run');

        $saved = $this->activity($repository, $entityManager)->saveUpdates(
            new Update(updateId: 503),
            workflowChatId: -100123,
            ingestionRunId: 'workflow/current-run/ingestion-1',
        );

        self::assertFalse($saved);
    }

    public function testDeterministicSerializationFailureIsNonRetryable(): void
    {
        $repository    = new InMemoryUpdateRecordRepository();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');
        $entityManager->expects($this->never())->method('run');
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer
            ->method('serialize')
            ->willThrowException(new RuntimeException('unsupported Telegram payload'));

        try {
            $this->activity($repository, $entityManager, $serializer)->saveUpdates(
                new Update(updateId: 504),
                workflowChatId: -100123,
                ingestionRunId: 'workflow/run-1/ingestion-3',
            );
            self::fail('A deterministic serialization failure must fail the activity.');
        } catch (ApplicationFailure $failure) {
            self::assertTrue($failure->isNonRetryable());
            self::assertSame('telegram-update-serialization', $failure->getType());
            self::assertStringContainsString('504', $failure->getOriginalMessage());
        }
    }

    public function testDatabaseFailureRemainsRetryableByTemporal(): void
    {
        $repository    = new InMemoryUpdateRecordRepository();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects($this->once())
            ->method('persist')
            ->willReturn($entityManager);
        $entityManager
            ->expects($this->once())
            ->method('run')
            ->willThrowException(new RuntimeException('database unavailable'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('database unavailable');

        $this->activity($repository, $entityManager)->saveUpdates(
            new Update(updateId: 505),
            workflowChatId: -100123,
            ingestionRunId: 'workflow/run-1/ingestion-4',
        );
    }

    private function activity(
        RepositoryInterface $repository,
        EntityManagerInterface $entityManager,
        ?SerializerInterface $serializer = null,
    ): TelegramActivity {
        $orm = $this->createMock(ORMInterface::class);
        $orm
            ->method('getRepository')
            ->with(UpdateRecord::class)
            ->willReturn($repository);

        return new TelegramActivity($orm, $entityManager, $serializer);
    }
}

/**
 * @implements RepositoryInterface<UpdateRecord>
 */
final class InMemoryUpdateRecordRepository implements RepositoryInterface
{
    /** @var array<int, UpdateRecord> */
    private array $records = [];

    /**
     * @param list<UpdateRecord> $records
     */
    public function __construct(array $records = [])
    {
        foreach ($records as $record) {
            $this->store($record);
        }
    }

    public function store(UpdateRecord $record): void
    {
        $this->records[$record->updateId] = $record;
    }

    public function find(int $updateId): ?UpdateRecord
    {
        return $this->records[$updateId] ?? null;
    }

    public function findByPK(mixed $id): ?object
    {
        return is_int($id) ? $this->find($id) : null;
    }

    public function findOne(array $scope = []): ?object
    {
        return null;
    }

    public function findAll(array $scope = []): iterable
    {
        return array_values($this->records);
    }
}
