<?php

declare(strict_types=1);

namespace Tests\Llm\Tools\Chat;

use Bot\Entity\UpdateRecord;
use Bot\Llm\Tools\Chat\GetCurrentTimeExecutor;
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

    /**
     * @param list<UpdateRecord> $records
     */
    private function makeUpdateRepo(array $records): RepositoryInterface
    {
        return new class($records) implements RepositoryInterface {
            /**
             * @param list<UpdateRecord> $records
             */
            public function __construct(private readonly array $records) {}

            /**
             * @return list<UpdateRecord>
             */
            public function findLastN(int $chatId, int $limit): array
            {
                return array_slice(
                    array_values(array_filter(
                        $this->records,
                        static fn (UpdateRecord $record): bool => $record->chatId === $chatId,
                    )),
                    0,
                    $limit,
                );
            }

            /**
             * @param list<string> $tokens
             * @return list<UpdateRecord>
             */
            public function searchByPayloadText(int $chatId, array $tokens, int $limit): array
            {
                $records = array_values(array_filter(
                    $this->records,
                    static function (UpdateRecord $record) use ($chatId, $tokens): bool {
                        if ($record->chatId !== $chatId) {
                            return false;
                        }

                        $payload = mb_strtolower($record->update);

                        foreach ($tokens as $token) {
                            if (!str_contains($payload, $token)) {
                                return false;
                            }
                        }

                        return true;
                    },
                ));

                usort(
                    $records,
                    static fn (UpdateRecord $left, UpdateRecord $right): int => [$right->createdAt, $right->updateId]
                        <=> [$left->createdAt, $left->updateId],
                );

                return array_slice($records, 0, $limit);
            }

            /** @return list<UpdateRecord> */
            public function findLastNInTopic(int $chatId, ?int $topicId, int $limit): array
            {
                return array_slice(array_values(array_filter(
                    $this->records,
                    static fn (UpdateRecord $record): bool => $record->chatId === $chatId
                        && $record->topicId === $topicId,
                )), 0, $limit);
            }

            /**
             * @param list<string> $tokens
             * @return list<UpdateRecord>
             */
            public function searchByPayloadTextInTopic(
                int $chatId,
                ?int $topicId,
                array $tokens,
                int $limit,
            ): array {
                return array_slice(array_values(array_filter(
                    $this->searchByPayloadText($chatId, $tokens, $limit),
                    static fn (UpdateRecord $record): bool => $record->topicId === $topicId,
                )), 0, $limit);
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

    private function makeOrm(RepositoryInterface $updateRepo): ORMInterface
    {
        $orm = Mockery::mock(ORMInterface::class);
        $orm->shouldReceive('getRepository')
            ->atLeast()
            ->once()
            ->with(UpdateRecord::class)
            ->andReturn($updateRepo);

        return $orm;
    }

    public function testSearchMessagesExecutorLoadsRecentHistoryWhenQueryIsEmpty(): void
    {
        $chatId = -100123;
        $repo = $this->makeUpdateRepo([
            $this->makeUpdateRecord(2, $chatId, 'second message', 200, 'bob'),
            $this->makeUpdateRecord(1, $chatId, 'first message', 100, 'alice'),
        ]);

        $executor = new SearchMessagesExecutor($this->makeOrm($repo));
        $result = $executor->execute(chatId: $chatId, resultLimit: 2);

        self::assertStringContainsString('Recent inbound Telegram history', $result);
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

        $executor = new SearchMessagesExecutor($this->makeOrm($repo));
        $result = $executor->execute(
            chatId: $chatId,
            queryText: 'deploy',
            usernameText: '@alice',
            resultLimit: 5,
        );

        self::assertStringContainsString('Relevant inbound Telegram history', $result);
        self::assertStringContainsString('deploy plan is ready', $result);
        self::assertStringNotContainsString('deploy failed on staging', $result);
        self::assertStringNotContainsString('random chat', $result);
    }

    public function testSearchMessagesExecutorSearchesBeyondRecentWindowWhenQueryIsPresent(): void
    {
        $chatId = -100123;
        $records = [];

        for ($i = 400; $i >= 101; --$i) {
            $records[] = $this->makeUpdateRecord($i, $chatId, 'recent filler ' . $i, $i, 'alice');
        }

        $records[] = $this->makeUpdateRecord(1, $chatId, 'ancient deploy decision', 1, 'bob');

        $executor = new SearchMessagesExecutor($this->makeOrm($this->makeUpdateRepo($records)));
        $result = $executor->execute(
            chatId: $chatId,
            queryText: 'ancient deploy',
            resultLimit: 5,
        );

        self::assertStringContainsString('Relevant inbound Telegram history', $result);
        self::assertStringContainsString('ancient deploy decision', $result);
        self::assertStringNotContainsString('recent filler', $result);
    }

    public function testSearchMessagesExecutorSearchesTheWholeChatAcrossTopics(): void
    {
        $chatId = -100123;
        $repo = $this->makeUpdateRepo([
            $this->makeUpdateRecord(2, $chatId, 'topic message', 200, 'bob', topicId: 42),
            $this->makeUpdateRecord(1, $chatId, 'general message', 100, 'alice'),
        ]);

        $executor = new SearchMessagesExecutor($this->makeOrm($repo));
        $result = $executor->execute(chatId: $chatId, resultLimit: 5);

        self::assertStringContainsString('general message', $result);
        self::assertStringContainsString('topic message', $result);
    }

    public function testSpaceSearchNeverCrossesTheTopicBoundary(): void
    {
        $chatId = -100123;
        $repo = $this->makeUpdateRepo([
            $this->makeUpdateRecord(3, $chatId, 'private topic 99 note', 300, 'eve', topicId: 99),
            $this->makeUpdateRecord(2, $chatId, 'private topic 42 note', 200, 'bob', topicId: 42),
            $this->makeUpdateRecord(1, $chatId, 'root private note', 100, 'alice'),
        ]);
        $executor = new SearchMessagesExecutor($this->makeOrm($repo));

        $topic = $executor->executeInSpace($chatId, 42, 'private', resultLimit: 10);
        self::assertStringContainsString('topic 42', $topic);
        self::assertStringNotContainsString('topic 99', $topic);
        self::assertStringNotContainsString('root private', $topic);

        $root = $executor->executeInSpace($chatId, null, 'private', resultLimit: 10);
        self::assertStringContainsString('root private', $root);
        self::assertStringNotContainsString('topic 42', $root);
        self::assertStringNotContainsString('topic 99', $root);
    }

    public function testSearchMessagesExecutorReturnsAUsefulNoMatchMessage(): void
    {
        $chatId = -100123;
        $repo = $this->makeUpdateRepo([
            $this->makeUpdateRecord(1, $chatId, 'human deploy note', 100, 'alice'),
        ]);

        $executor = new SearchMessagesExecutor($this->makeOrm($repo));
        $result = $executor->execute(
            chatId: $chatId,
            queryText: 'missing phrase',
            usernameText: '@bob',
        );

        self::assertSame('No messages found matching "missing phrase" for @bob.', $result);
    }

    public function testGetCurrentTimeExecutorReturnsFormattedTime(): void
    {
        $executor = new GetCurrentTimeExecutor();
        $result = $executor->execute('UTC');

        self::assertMatchesRegularExpression(
            '/^Current time in UTC: \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} \([A-Za-z]+\)$/',
            $result,
        );
    }

    public function testGetCurrentTimeExecutorRejectsUnknownTimezone(): void
    {
        $executor = new GetCurrentTimeExecutor();

        self::assertStringStartsWith(
            'Unknown timezone: Mars/Olympus_Mons.',
            $executor->execute('Mars/Olympus_Mons'),
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
