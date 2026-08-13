<?php

declare(strict_types=1);

namespace Tests\Llm\Tools\Chat;

use Bot\Entity\UpdateRecord;
use Bot\Llm\Tools\Chat\BotMessageHistoryItem;
use Bot\Llm\Tools\Chat\BotMessageHistorySourceInterface;
use Bot\Llm\Tools\Chat\DatabaseBotMessageHistorySource;
use Bot\Llm\Tools\Chat\SearchMessagesExecutor;
use Bot\Telegram\Factory;
use Bot\Telegram\Update;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\StatementInterface;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\RepositoryInterface;
use DateTimeImmutable;
use DateTimeZone;
use Mockery;
use Phenogram\Bindings\Serializer;
use Phenogram\Bindings\Types\Chat;
use Phenogram\Bindings\Types\Message;
use Phenogram\Bindings\Types\User;
use Tests\TestCase;

final class BotMessageHistorySourceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testDatabaseSourceAcceptsOnlyProvenSuccessfulTelegramSends(): void
    {
        $chatId = -100123;
        $legacy = Mockery::mock(StatementInterface::class);
        $legacy->shouldReceive('fetchAll')->once()->andReturn([
            [
                'id'         => 1,
                'created_at' => 100,
                'payload'    => json_encode([
                    'role'    => 'tool',
                    'content' => self::telegramSuccess($chatId, 100, 'legacy notebook answer'),
                ], \JSON_THROW_ON_ERROR),
            ],
            [
                'id'         => 2,
                'created_at' => 110,
                'payload'    => json_encode([
                    'role'    => 'tool',
                    'content' => self::telegramSuccess(-999, 110, 'wrong chat notebook answer'),
                ], \JSON_THROW_ON_ERROR),
            ],
            [
                'id'         => 5,
                'created_at' => 120,
                'payload'    => json_encode([
                    'role'    => 'tool',
                    'content' => self::telegramSuccess($chatId, 0, 'missing date notebook answer'),
                ], \JSON_THROW_ON_ERROR),
            ],
        ]);
        $current = Mockery::mock(StatementInterface::class);
        $current->shouldReceive('fetchAll')->once()->andReturn([
            [
                'id'           => 3,
                'completed_at' => 180,
                'result_json'  => json_encode([
                    'name'    => 'telegram_api_call',
                    'isError' => false,
                    'content' => [[
                        'type' => 'text',
                        'text' => self::telegramSuccess($chatId, 180, 'current notebook answer'),
                    ]],
                    'metadata' => ['chatId' => $chatId],
                ], \JSON_THROW_ON_ERROR),
            ],
            [
                'id'           => 4,
                'completed_at' => 190,
                'result_json'  => json_encode([
                    'name'    => 'telegram_api_call',
                    'isError' => true,
                    'content' => [[
                        'type' => 'text',
                        'text' => self::telegramSuccess($chatId, 190, 'failed notebook answer'),
                    ]],
                    'metadata' => ['chatId' => $chatId],
                ], \JSON_THROW_ON_ERROR),
            ],
        ]);

        $database = Mockery::mock(DatabaseInterface::class);
        $database->shouldReceive('query')
            ->once()
            ->withArgs(static function (string $sql, array $parameters) use ($chatId): bool {
                return str_contains($sql, 'FROM llm_provider_responses')
                    && str_contains($sql, "(payload::jsonb ->> 'content') ILIKE")
                    && in_array(
                        'Shanginn\Openai\ChatCompletion\Message\ToolMessage',
                        $parameters,
                        true,
                    )
                    && !in_array(
                        'Shanginn\Openai\ChatCompletion\Message\AssistantMessage',
                        $parameters,
                        true,
                    )
                    && $parameters[0] === $chatId;
            })
            ->andReturn($legacy);
        $database->shouldReceive('query')
            ->once()
            ->withArgs(static fn (string $sql, array $parameters): bool => str_contains(
                $sql,
                'FROM tool_execution_records',
            ) && str_contains($sql, "result_json::jsonb #>> '{metadata,chatId}' = ?")
                && str_contains($sql, "(result_json::jsonb #>> '{content,0,text}') ILIKE")
                && $parameters[0] === (string) $chatId)
            ->andReturn($current);

        $items = (new DatabaseBotMessageHistorySource($database))->search(
            chatId: $chatId,
            topicId: null,
            topicScoped: false,
            queryTokens: ['notebook'],
            startInclusive: 90,
            endExclusive: 200,
            limit: 10,
        );

        self::assertSame(
            ['legacy notebook answer', 'current notebook answer'],
            array_map(static fn (BotMessageHistoryItem $item): string => $item->text, $items),
        );
        self::assertSame([100, 180], array_column($items, 'createdAt'));
    }

    public function testDatabaseSourceSearchesDecodedCyrillicContentNotRawJsonEscapes(): void
    {
        $statement = Mockery::mock(StatementInterface::class);
        $statement->shouldReceive('fetchAll')->andReturn([]);
        $database = Mockery::mock(DatabaseInterface::class);
        $database->shouldReceive('query')
            ->once()
            ->withArgs(static fn (string $sql, array $parameters): bool => str_contains(
                $sql,
                "(payload::jsonb ->> 'content') ILIKE ? ESCAPE '!'",
            ) && in_array('%блокнотик%', $parameters, true))
            ->andReturn($statement);
        $database->shouldReceive('query')
            ->once()
            ->withArgs(static fn (string $sql, array $parameters): bool => str_contains(
                $sql,
                "(result_json::jsonb #>> '{content,0,text}') ILIKE ? ESCAPE '!'",
            ) && in_array('%блокнотик%', $parameters, true))
            ->andReturn($statement);

        self::assertSame([], (new DatabaseBotMessageHistorySource($database))->search(
            chatId: -100123,
            topicId: null,
            topicScoped: false,
            queryTokens: ['блокнотик'],
            startInclusive: null,
            endExclusive: null,
            limit: 10,
        ));
    }

    public function testExecutorMergesBotOutputWithTrustedDateQueryAndBotAlias(): void
    {
        $chatId = -100123;
        $zone   = new DateTimeZone('Asia/Yekaterinburg');
        $start  = (new DateTimeImmutable('2026-05-13 00:00:00', $zone))->getTimestamp();
        $repo   = Mockery::mock(RepositoryInterface::class);
        $repo->shouldReceive('searchInPeriod')
            ->once()
            ->andReturn([]);
        $orm = Mockery::mock(ORMInterface::class);
        $orm->shouldReceive('getRepository')
            ->once()
            ->with(UpdateRecord::class)
            ->andReturn($repo);
        $source = new class($chatId, $start) implements BotMessageHistorySourceInterface {
            /** @var array<string, mixed> */
            public array $request = [];

            public function __construct(
                private readonly int $expectedChatId,
                private readonly int $dayStart,
            ) {}

            public function search(
                int $chatId,
                ?int $topicId,
                bool $topicScoped,
                array $queryTokens,
                ?int $startInclusive,
                ?int $endExclusive,
                int $limit,
            ): array {
                $this->request = get_defined_vars();

                return $chatId === $this->expectedChatId
                    ? [new BotMessageHistoryItem(
                        $this->dayStart + 60,
                        'the notebook automation was enabled',
                        2,
                    )]
                    : [];
            }
        };

        $result = (new SearchMessagesExecutor(
            $orm,
            botMessageHistory: $source,
        ))->execute(
            chatId: $chatId,
            queryText: 'notebook',
            usernameText: 'assistant',
            onDate: '2026-05-13',
        );

        self::assertSame($chatId, $source->request['chatId']);
        self::assertSame(['notebook'], $source->request['queryTokens']);
        self::assertSame($start, $source->request['startInclusive']);
        self::assertSame($start + 86_400, $source->request['endExclusive']);
        self::assertStringContainsString('Bot output', $result);
        self::assertStringContainsString('the notebook automation was enabled', $result);
    }

    public function testPeriodPaginationAcrossInboundAndBotHistoryHasNoOverlapOrGap(): void
    {
        $chatId     = -100123;
        $zone       = new DateTimeZone('Asia/Yekaterinburg');
        $start      = (new DateTimeImmutable('2026-05-13 00:00:00', $zone))->getTimestamp();
        $serializer = new Serializer(new Factory());
        $records    = [];
        for ($index = 0; $index < 4; ++$index) {
            $update = new Update(
                updateId: $index + 1,
                message: new Message(
                    messageId: $index + 1,
                    date: $start + $index * 20,
                    chat: new Chat(id: $chatId, type: 'supergroup'),
                    from: new User(id: 10, isBot: false, firstName: 'User', username: 'alice'),
                    text: 'inbound-' . $index,
                ),
            );
            $records[] = new UpdateRecord(
                updateId: $index + 1,
                update: json_encode($serializer->serialize([$update])[0], \JSON_THROW_ON_ERROR),
                chatId: $chatId,
                createdAt: $start + $index * 20,
            );
        }
        $repo = Mockery::mock(RepositoryInterface::class);
        $repo->shouldReceive('searchInPeriod')
            ->twice()
            ->andReturn($records);
        $orm = Mockery::mock(ORMInterface::class);
        $orm->shouldReceive('getRepository')
            ->twice()
            ->with(UpdateRecord::class)
            ->andReturn($repo);
        $source = new class($start) implements BotMessageHistorySourceInterface {
            public function __construct(private readonly int $start) {}

            public function search(
                int $chatId,
                ?int $topicId,
                bool $topicScoped,
                array $queryTokens,
                ?int $startInclusive,
                ?int $endExclusive,
                int $limit,
            ): array {
                return [
                    new BotMessageHistoryItem($this->start + 10, 'bot-0', 2),
                    new BotMessageHistoryItem($this->start + 30, 'bot-1', 2),
                    new BotMessageHistoryItem($this->start + 50, 'bot-2', 2),
                    new BotMessageHistoryItem($this->start + 70, 'bot-3', 2),
                ];
            }
        };
        $executor = new SearchMessagesExecutor($orm, botMessageHistory: $source);

        $pageOne = $executor->execute(
            chatId: $chatId,
            onDate: '2026-05-13',
            resultLimit: 3,
        );
        $pageTwo = $executor->execute(
            chatId: $chatId,
            onDate: '2026-05-13',
            resultLimit: 3,
            offset: 3,
        );

        self::assertStringContainsString('inbound-0', $pageOne);
        self::assertStringContainsString('bot-0', $pageOne);
        self::assertStringContainsString('inbound-1', $pageOne);
        self::assertStringNotContainsString('inbound-1', $pageTwo);
        self::assertStringContainsString('bot-1', $pageTwo);
        self::assertStringContainsString('inbound-2', $pageTwo);
        self::assertStringContainsString('bot-2', $pageTwo);
        self::assertStringNotContainsString('bot-0', $pageTwo);
    }

    private static function telegramSuccess(int $chatId, int $date, string $text): string
    {
        return 'Telegram API call succeeded: ' . json_encode([
            'ok'     => true,
            'method' => 'sendMessage',
            'result' => [
                'message_id' => $date,
                'date'       => $date,
                'chat'       => ['id' => $chatId, 'type' => 'supergroup'],
                'text'       => $text,
            ],
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }
}
