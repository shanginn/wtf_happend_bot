<?php

declare(strict_types=1);

namespace Tests\Llm\Tools\Chat;

use Bot\Entity\UpdateRecord;
use Bot\Llm\Tools\Chat\GetCurrentTime;
use Bot\Llm\Tools\Chat\GetCurrentTimeExecutor;
use Bot\Llm\Tools\Chat\SearchMessages;
use Bot\Llm\Tools\Chat\SearchMessagesExecutor;
use Bot\Telegram\Factory;
use Bot\Telegram\Update;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\RepositoryInterface;
use Mockery;
use Phenogram\Bindings\Serializer;
use Phenogram\Bindings\Types\Chat;
use Phenogram\Bindings\Types\Message;
use Phenogram\Bindings\Types\User;
use Tests\TestCase;

class ChatExecutorsTest extends TestCase
{
    private function makeUpdateRecord(
        int $updateId,
        int $chatId,
        string $text,
        int $createdAt,
        string $username,
        ?int $topicId = null,
    ): UpdateRecord {
        $serializer = new Serializer(new Factory());
        $update = new Update(
            updateId: $updateId,
            message: new Message(
                messageId: $updateId,
                date: $createdAt,
                chat: new Chat(id: $chatId, type: 'supergroup', title: 'Tea Room'),
                from: new User(id: 10 + $updateId, isBot: false, firstName: 'User', username: $username),
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

    public function testSearchMessagesExecutorLoadsRecentHistoryWhenQueryIsEmpty(): void
    {
        $chatId = -100123;
        $repo = $this->makeUpdateRepo([
            $this->makeUpdateRecord(2, $chatId, 'second message', 200, 'bob'),
            $this->makeUpdateRecord(1, $chatId, 'first message', 100, 'alice'),
        ]);

        $orm = Mockery::mock(ORMInterface::class);
        $orm->shouldReceive('getRepository')->with(UpdateRecord::class)->andReturn($repo);

        $executor = new SearchMessagesExecutor($orm);
        $result = $executor->execute($chatId, new SearchMessages(limit: 2));

        self::assertStringContainsString('Recent chat history', $result);
        self::assertTrue(strpos($result, 'first message') < strpos($result, 'second message'));
    }

    public function testSearchMessagesExecutorFiltersByQueryAndUsername(): void
    {
        $chatId = -100123;
        $repo = $this->makeUpdateRepo([
            $this->makeUpdateRecord(3, $chatId, 'deploy plan is ready', 300, 'alice'),
            $this->makeUpdateRecord(2, $chatId, 'deploy failed on staging', 200, 'bob'),
            $this->makeUpdateRecord(1, $chatId, 'random chat', 100, 'alice'),
        ]);

        $orm = Mockery::mock(ORMInterface::class);
        $orm->shouldReceive('getRepository')->with(UpdateRecord::class)->andReturn($repo);

        $executor = new SearchMessagesExecutor($orm);
        $result = $executor->execute(
            $chatId,
            new SearchMessages(query: 'deploy', username: '@alice', limit: 5),
        );

        self::assertStringContainsString('Relevant chat history', $result);
        self::assertStringContainsString('deploy plan is ready', $result);
        self::assertStringNotContainsString('deploy failed on staging', $result);
        self::assertStringNotContainsString('random chat', $result);
    }

    public function testSearchMessagesExecutorIgnoresTopicAndSearchesWholeChat(): void
    {
        $chatId = -100123;
        $repo = $this->makeUpdateRepo([
            $this->makeUpdateRecord(2, $chatId, 'topic message', 200, 'bob', topicId: 42),
            $this->makeUpdateRecord(1, $chatId, 'general message', 100, 'alice'),
        ]);

        $orm = Mockery::mock(ORMInterface::class);
        $orm->shouldReceive('getRepository')->with(UpdateRecord::class)->andReturn($repo);

        $executor = new SearchMessagesExecutor($orm);
        $result = $executor->execute($chatId, new SearchMessages(limit: 5));

        self::assertStringContainsString('general message', $result);
        self::assertStringContainsString('topic message', $result);
    }

    public function testGetCurrentTimeExecutorReturnsFormattedTime(): void
    {
        $executor = new GetCurrentTimeExecutor();
        $result = $executor->execute(-100123, new GetCurrentTime('UTC'));

        self::assertStringContainsString('Current time in UTC:', $result);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
