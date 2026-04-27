<?php

declare(strict_types=1);

namespace Tests\AgenticWorkflow;

use Bot\AgenticWorkflow\AgenticActivity;
use Bot\Entity\LlmProviderResponse;
use Bot\Entity\ParticipantMemory;
use Bot\Entity\UpdateRecord;
use Bot\Llm\ProviderHistory\LlmProviderType;
use Bot\Telegram\Factory;
use Bot\Telegram\Update;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\RepositoryInterface;
use Mockery;
use Phenogram\Bindings\ApiInterface;
use Phenogram\Bindings\Serializer;
use Phenogram\Bindings\Types\Chat;
use Phenogram\Bindings\Types\Message;
use Phenogram\Bindings\Types\User;
use Shanginn\Openai\ChatCompletion\CompletionResponse;
use Shanginn\Openai\ChatCompletion\CompletionResponse\Choice;
use Shanginn\Openai\ChatCompletion\CompletionResponse\Usage;
use Shanginn\Openai\ChatCompletion\ErrorResponse;
use Shanginn\Openai\ChatCompletion\Message\AssistantMessage;
use Shanginn\Openai\ChatCompletion\Message\UserMessage;
use Shanginn\Openai\Openai;
use Shanginn\Openai\Openai\OpenaiSerializer;
use Tests\TestCase;

class AgenticActivityTest extends TestCase
{
    private function makeUpdateRepo(array $records): RepositoryInterface
    {
        return new class($records) implements RepositoryInterface {
            public function __construct(private readonly array $records) {}

            public function findLastN(int $chatId, int $limit): array
            {
                return $this->records;
            }

            public function findByPK(mixed $id): ?object
            {
                return null;
            }

            public function findOne(array $scope = []): ?object
            {
                return null;
            }

            public function findAll(array $scope = []): iterable
            {
                return [];
            }
        };
    }

    private function makeResponseRepo(array $records, array &$saved): RepositoryInterface
    {
        return new class($records, $saved) implements RepositoryInterface {
            public function __construct(
                private readonly array $records,
                private array &$saved,
            ) {}

            public function findLastNByChat(int $chatId, LlmProviderType $type, int $limit): array
            {
                return $this->records;
            }

            public function save(LlmProviderResponse $record, bool $run = true): void
            {
                $this->saved[] = $record;
            }

            public function findByPK(mixed $id): ?object
            {
                return null;
            }

            public function findOne(array $scope = []): ?object
            {
                return null;
            }

            public function findAll(array $scope = []): iterable
            {
                return [];
            }
        };
    }

    private function makeParticipantMemoryRepo(array $records): RepositoryInterface
    {
        return new class($records) implements RepositoryInterface {
            public function __construct(private readonly array $records) {}

            public function findByChatId(int $chatId): array
            {
                return $this->records;
            }

            public function findByPK(mixed $id): ?object
            {
                return null;
            }

            public function findOne(array $scope = []): ?object
            {
                return null;
            }

            public function findAll(array $scope = []): iterable
            {
                return [];
            }
        };
    }

    private function makeUpdateRecord(
        int $updateId,
        int $chatId,
        string $text,
        int $createdAt,
        ?int $topicId = null,
    ): UpdateRecord {
        $serializer = new Serializer(new Factory());
        $update = new Update(
            updateId: $updateId,
            message: new Message(
                messageId: $updateId,
                date: $createdAt,
                chat: new Chat(id: $chatId, type: 'supergroup', title: 'Tea Room'),
                from: new User(id: 10 + $updateId, isBot: false, firstName: 'Alice', username: 'alice'),
                text: $text,
                messageThreadId: $topicId,
            ),
        );

        return new UpdateRecord(
            updateId: $updateId,
            update: json_encode($serializer->serialize([$update])[0], \JSON_THROW_ON_ERROR),
            chatId: $chatId,
            topicId: $topicId,
            createdAt: $createdAt,
        );
    }

    private function makeResponseRecord(
        AssistantMessage $message,
        int $chatId,
        int $createdAt,
        ?int $topicId = null,
    ): LlmProviderResponse {
        $serializer = new OpenaiSerializer();

        $record = new LlmProviderResponse(
            chatId: $chatId,
            topicId: $topicId,
            type: LlmProviderType::Openai,
            messageClass: $message::class,
            payload: $serializer->serialize($message),
            createdAt: $createdAt,
        );

        $record->id = $createdAt;

        return $record;
    }

    public function testCompleteReconstructsHistoryFromUpdatesAndResponsesSortedByCreatedAt(): void
    {
        $chatId = -100123;
        $saved = [];
        $updateRepo = $this->makeUpdateRepo([$this->makeUpdateRecord(2, $chatId, 'new message', 200)]);
        $responseRepo = $this->makeResponseRepo(
            [$this->makeResponseRecord(new AssistantMessage('earlier reply'), $chatId, 100)],
            $saved,
        );

        $orm = Mockery::mock(ORMInterface::class);
        $orm->shouldReceive('getRepository')->with(UpdateRecord::class)->andReturn($updateRepo);
        $orm->shouldReceive('getRepository')->with(LlmProviderResponse::class)->andReturn($responseRepo);

        $openai = $this->createMock(Openai::class);
        $openai
            ->expects($this->once())
            ->method('completion')
            ->willReturnCallback(function (
                array $messages,
                ?string $system = null,
                ?float $temperature = null,
                ?int $maxTokens = null,
                ?int $maxCompletionTokens = null,
                ?float $frequencyPenalty = null,
                mixed $toolChoice = null,
                ?array $tools = null,
            ) {
                $this->assertCount(2, $messages);
                $this->assertInstanceOf(AssistantMessage::class, $messages[0]);
                $this->assertSame('earlier reply', $messages[0]->content);
                $this->assertInstanceOf(UserMessage::class, $messages[1]);
                $this->assertIsString($messages[1]->content);
                $this->assertStringContainsString('new message', $messages[1]->content);

                return new ErrorResponse(
                    message: 'synthetic',
                    type: null,
                    param: null,
                    code: null,
                    rawResponse: '',
                );
            });

        $activity = new AgenticActivity(
            openai: $openai,
            api: $this->createStub(ApiInterface::class),
            orm: $orm,
        );

        $activity->complete($chatId, null);
    }

    public function testCompleteSavesAssistantReplyAsLlmProviderResponse(): void
    {
        $chatId = -100123;
        $saved = [];

        $updateRepo = $this->makeUpdateRepo([$this->makeUpdateRecord(2, $chatId, 'hello', 200)]);
        $responseRepo = $this->makeResponseRepo([], $saved);

        $orm = Mockery::mock(ORMInterface::class);
        $orm->shouldReceive('getRepository')->with(UpdateRecord::class)->andReturn($updateRepo);
        $orm->shouldReceive('getRepository')->with(LlmProviderResponse::class)->andReturn($responseRepo);

        $assistantMessage = new AssistantMessage('saved reply');
        $completion = new CompletionResponse(
            id: 'resp_1',
            choices: [new Choice(index: 0, message: $assistantMessage, finishReason: 'stop')],
            model: 'test-model',
            usage: new Usage(completionTokens: 1, promptTokens: 1, totalTokens: 2),
            object: 'chat.completion',
            created: 300,
        );

        $openai = $this->createMock(Openai::class);
        $openai->expects($this->once())->method('completion')->willReturn($completion);

        $activity = new AgenticActivity(
            openai: $openai,
            api: $this->createStub(ApiInterface::class),
            orm: $orm,
        );

        $result = $activity->complete($chatId, null);

        $this->assertSame($completion, $result);
        $this->assertCount(1, $saved);
        $this->assertSame(LlmProviderType::Openai, $saved[0]->type);
        $this->assertSame(AssistantMessage::class, $saved[0]->messageClass);
        $this->assertNotNull($saved[0]->rawResponse);
    }

    public function testCompleteLoadsHistoryAcrossTopicsInTheSameChat(): void
    {
        $chatId = -100123;
        $saved = [];
        $updateRepo = $this->makeUpdateRepo([
            $this->makeUpdateRecord(2, $chatId, 'topic message', 200, topicId: 42),
            $this->makeUpdateRecord(1, $chatId, 'general message', 100),
        ]);
        $responseRepo = $this->makeResponseRepo([], $saved);

        $orm = Mockery::mock(ORMInterface::class);
        $orm->shouldReceive('getRepository')->with(UpdateRecord::class)->andReturn($updateRepo);
        $orm->shouldReceive('getRepository')->with(LlmProviderResponse::class)->andReturn($responseRepo);

        $openai = $this->createMock(Openai::class);
        $openai
            ->expects($this->once())
            ->method('completion')
            ->willReturnCallback(function (array $messages) {
                $historyText = implode(
                    "\n",
                    array_map(
                        static fn (object $message): string => (string) ($message->content ?? ''),
                        $messages,
                    ),
                );

                $this->assertStringContainsString('general message', $historyText);
                $this->assertStringContainsString('topic message', $historyText);

                return new ErrorResponse(
                    message: 'synthetic',
                    type: null,
                    param: null,
                    code: null,
                    rawResponse: '',
                );
            });

        $activity = new AgenticActivity(
            openai: $openai,
            api: $this->createStub(ApiInterface::class),
            orm: $orm,
        );

        $activity->complete($chatId, 42);
    }

    public function testLoadAllParticipantMemoriesFormatsSavedRecords(): void
    {
        $chatId = -100123;
        $memory = new ParticipantMemory(
            chatId: $chatId,
            participantKey: '@alice',
            participantLabel: '@alice',
            memory: 'Alice owns the deployment pipeline',
            quote: 'I am on call for deploys this week',
            context: 'They were assigning release responsibilities.',
            createdAt: 100,
            updatedAt: 200,
        );
        $memory->id = 1;

        $orm = Mockery::mock(ORMInterface::class);
        $orm->shouldReceive('getRepository')->with(ParticipantMemory::class)->andReturn(
            $this->makeParticipantMemoryRepo([$memory]),
        );

        $activity = new AgenticActivity(
            openai: $this->createStub(Openai::class),
            api: $this->createStub(ApiInterface::class),
            orm: $orm,
        );

        $formatted = $activity->loadAllParticipantMemories($chatId);

        $this->assertStringContainsString('All participant memories:', $formatted);
        $this->assertStringContainsString('@alice | memory: Alice owns the deployment pipeline', $formatted);
        $this->assertStringContainsString('quote: I am on call for deploys this week', $formatted);
        $this->assertStringContainsString('context: They were assigning release responsibilities.', $formatted);
    }

    public function testRecollectRelevantMemoriesUsesDedicatedOpenaiWhenProvided(): void
    {
        $chatId = -100123;
        $memory = new ParticipantMemory(
            chatId: $chatId,
            participantKey: '@alice',
            participantLabel: '@alice',
            memory: 'Alice owns the deployment pipeline',
            quote: 'I am on call for deploys this week',
            context: 'They were assigning release responsibilities.',
            createdAt: 100,
            updatedAt: 200,
        );
        $memory->id = 1;

        $orm = Mockery::mock(ORMInterface::class);
        $orm->shouldReceive('getRepository')->with(ParticipantMemory::class)->andReturn(
            $this->makeParticipantMemoryRepo([$memory]),
        );

        $responseOpenai = $this->createMock(Openai::class);
        $responseOpenai->expects($this->never())->method('completion');

        $decisionOpenai = $this->createMock(Openai::class);
        $decisionOpenai->expects($this->never())->method('completion');

        $expectedResponse = new ErrorResponse(
            message: 'synthetic',
            type: null,
            param: null,
            code: null,
            rawResponse: '',
        );

        $memoryOpenai = $this->createMock(Openai::class);
        $memoryOpenai
            ->expects($this->once())
            ->method('completion')
            ->willReturnCallback(function (array $messages, ?string $system = null) use ($expectedResponse) {
                $this->assertCount(2, $messages);
                $this->assertStringContainsString('All participant memories:', (string) $messages[1]->content);
                $this->assertIsString($system);
                $this->assertStringContainsString('relevant memories agent', $system);

                return $expectedResponse;
            });

        $activity = new AgenticActivity(
            openai: $responseOpenai,
            api: $this->createStub(ApiInterface::class),
            orm: $orm,
            decisionOpenai: $decisionOpenai,
            memoryRecollectionOpenai: $memoryOpenai,
        );

        $result = $activity->recollectRelevantMemories(
            $chatId,
            [new UserMessage('who owns deploys?')],
        );

        $this->assertSame($expectedResponse, $result);
    }

    public function testCompactWorkingMemoryReturnsUpdatedCompactedContext(): void
    {
        $expectedContext = "- Alice owns deploys\n- Rollback plan still missing";

        $openai = $this->createMock(Openai::class);
        $openai
            ->expects($this->once())
            ->method('completion')
            ->willReturnCallback(function (array $messages, ?string $system = null) use ($expectedContext) {
                $this->assertCount(2, $messages);
                $this->assertSame('deploy notes', $messages[0]->content);
                $this->assertStringContainsString('Existing compacted context:', (string) $messages[1]->content);
                $this->assertStringContainsString('Alice is release lead', (string) $messages[1]->content);
                $this->assertIsString($system);
                $this->assertStringContainsString('working memory compaction agent', $system);

                return new CompletionResponse(
                    id: 'resp_compact_1',
                    choices: [new Choice(index: 0, message: new AssistantMessage($expectedContext), finishReason: 'stop')],
                    model: 'test-model',
                    usage: new Usage(completionTokens: 1, promptTokens: 1, totalTokens: 2),
                    object: 'chat.completion',
                    created: 400,
                );
            });

        $activity = new AgenticActivity(
            openai: $openai,
            api: $this->createStub(ApiInterface::class),
            orm: Mockery::mock(ORMInterface::class),
        );

        $result = $activity->compactWorkingMemory(
            existingCompactedContext: '- Alice is release lead',
            memory: [new UserMessage('deploy notes')],
        );

        $this->assertSame($expectedContext, $result);
    }

    public function testCompactWorkingMemoryReturnsNullOnErrorResponse(): void
    {
        $openai = $this->createMock(Openai::class);
        $openai
            ->expects($this->once())
            ->method('completion')
            ->willReturn(new ErrorResponse(
                message: 'synthetic',
                type: null,
                param: null,
                code: null,
                rawResponse: '',
            ));

        $activity = new AgenticActivity(
            openai: $openai,
            api: $this->createStub(ApiInterface::class),
            orm: Mockery::mock(ORMInterface::class),
        );

        $result = $activity->compactWorkingMemory(
            existingCompactedContext: '',
            memory: [new UserMessage('deploy notes')],
        );

        $this->assertNull($result);
    }

    public function testCompactWorkingMemoryReturnsNullOnEmptyModelOutput(): void
    {
        $openai = $this->createMock(Openai::class);
        $openai
            ->expects($this->once())
            ->method('completion')
            ->willReturn(new CompletionResponse(
                id: 'resp_compact_empty',
                choices: [new Choice(index: 0, message: new AssistantMessage('   '), finishReason: 'stop')],
                model: 'test-model',
                usage: new Usage(completionTokens: 1, promptTokens: 1, totalTokens: 2),
                object: 'chat.completion',
                created: 400,
            ));

        $activity = new AgenticActivity(
            openai: $openai,
            api: $this->createStub(ApiInterface::class),
            orm: Mockery::mock(ORMInterface::class),
        );

        $result = $activity->compactWorkingMemory(
            existingCompactedContext: '',
            memory: [new UserMessage('deploy notes')],
        );

        $this->assertNull($result);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
