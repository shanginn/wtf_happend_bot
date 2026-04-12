<?php

declare(strict_types=1);

namespace Tests\AgenticWorkflow;

use Bot\AgenticWorkflow\AgenticActivity;
use Bot\Entity\LlmProviderResponse;
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

            public function findLastNInTopic(int $chatId, ?int $topicId, int $limit): array
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

            public function findLastN(int $chatId, ?int $topicId, LlmProviderType $type, int $limit): array
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

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
