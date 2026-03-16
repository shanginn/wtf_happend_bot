<?php

declare(strict_types=1);

namespace Tests\Integration;

use Bot\Entity\UpdateRecord;
use Bot\RouterWorkflow\RouterActivity;
use Bot\RouterWorkflow\RouterWorkflowHandler;
use Bot\Telegram\Update;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\RepositoryInterface;
use Mockery;
use Phenogram\Bindings\ApiInterface;
use Phenogram\Bindings\Factories\ChatFactory;
use Phenogram\Bindings\Factories\MessageEntityFactory;
use Phenogram\Bindings\Factories\MessageFactory;
use Phenogram\Bindings\Factories\UpdateFactory;
use Phenogram\Bindings\Factories\UserFactory;
use Phenogram\Bindings\Serializer;
use Tests\TestCase;

/**
 * Integration tests that simulate realistic update flows using Phenogram factories.
 */
class UpdateProcessingTest extends TestCase
{
    private const int CHAT_ID = -100999888;
    private const int BOT_USER_ID = 777777;

    /**
     * Create a fake repository with a findLastN method (the real one is final).
     */
    private function makeFakeRepo(array $records): RepositoryInterface
    {
        return new class($records) implements RepositoryInterface {
            public function __construct(private readonly array $records) {}
            public function findLastN(int $chatId, int $limit): array { return $this->records; }
            public function findByPK(mixed $id): ?object { return null; }
            public function findOne(array $scope = []): ?object { return null; }
            public function findAll(array $scope = []): iterable { return []; }
        };
    }

    /**
     * Helper to create a realistic group chat update.
     */
    private function makeGroupUpdate(
        int $updateId,
        string $text,
        string $username = 'alice',
        string $firstName = 'Alice',
        ?int $replyToMessageId = null,
        ?int $messageThreadId = null,
    ): Update {
        $replyTo = null;
        if ($replyToMessageId !== null) {
            $replyTo = MessageFactory::make(
                messageId: $replyToMessageId,
                chat: ChatFactory::make(id: self::CHAT_ID, type: 'supergroup'),
            );
        }

        $update = UpdateFactory::make(
            updateId: $updateId,
            message: MessageFactory::make(
                messageId: $updateId * 100,
                chat: ChatFactory::make(id: self::CHAT_ID, type: 'supergroup'),
                from: UserFactory::make(
                    id: crc32($username),
                    isBot: false,
                    username: $username,
                    firstName: $firstName,
                ),
                text: $text,
                date: time() - (100 - $updateId),
                messageThreadId: $messageThreadId,
                replyToMessage: $replyTo,
            ),
        );

        self::assertInstanceOf(Update::class, $update);
        return $update;
    }

    /**
     * Helper to create a bot command update.
     */
    private function makeCommandUpdate(
        int $updateId,
        string $command,
        string $username = 'alice',
    ): Update {
        $update = UpdateFactory::make(
            updateId: $updateId,
            message: MessageFactory::make(
                messageId: $updateId * 100,
                chat: ChatFactory::make(id: self::CHAT_ID, type: 'supergroup'),
                from: UserFactory::make(
                    id: crc32($username),
                    isBot: false,
                    username: $username,
                ),
                text: $command,
                date: time(),
                entities: [
                    MessageEntityFactory::make(
                        type: 'bot_command',
                        offset: 0,
                        length: strlen(explode(' ', $command)[0]),
                    ),
                ],
            ),
        );

        self::assertInstanceOf(Update::class, $update);
        return $update;
    }

    /**
     * Helper to create a mention update (user mentions the bot).
     */
    private function makeMentionUpdate(
        int $updateId,
        string $text,
        string $username = 'alice',
        string $mentionedUsername = 'wtf_happened_bot',
    ): Update {
        $mentionOffset = strpos($text, '@' . $mentionedUsername);
        $mentionLength = strlen('@' . $mentionedUsername);

        $entities = [];
        if ($mentionOffset !== false) {
            $entities[] = MessageEntityFactory::make(
                type: 'mention',
                offset: $mentionOffset,
                length: $mentionLength,
            );
        }

        $update = UpdateFactory::make(
            updateId: $updateId,
            message: MessageFactory::make(
                messageId: $updateId * 100,
                chat: ChatFactory::make(id: self::CHAT_ID, type: 'supergroup'),
                from: UserFactory::make(
                    id: crc32($username),
                    isBot: false,
                    username: $username,
                ),
                text: $text,
                date: time(),
                entities: $entities,
            ),
        );

        self::assertInstanceOf(Update::class, $update);
        return $update;
    }

    /**
     * Serialize an update like TelegramActivity.saveUpdates does.
     */
    private function serializeUpdate(Update $update): string
    {
        $serializer = new Serializer();
        return json_encode($serializer->serialize([$update])[0]);
    }

    // -------------------------------------------------------------------------
    // Tests: Update creation and serialization
    // -------------------------------------------------------------------------

    public function testGroupUpdateHasCorrectStructure(): void
    {
        $update = $this->makeGroupUpdate(1, 'Hello everyone!', 'bob', 'Bob');

        self::assertSame(self::CHAT_ID, $update->effectiveChat->id);
        self::assertSame('bob', $update->effectiveUser->username);
        self::assertSame('Hello everyone!', $update->effectiveMessage->text);
    }

    public function testCommandUpdateHasBotCommandEntity(): void
    {
        $update = $this->makeCommandUpdate(1, '/wtf');

        self::assertSame('/wtf', $update->effectiveMessage->text);
        self::assertNotEmpty($update->effectiveMessage->entities);
        self::assertSame('bot_command', $update->effectiveMessage->entities[0]->type);
    }

    public function testMentionUpdateHasMentionEntity(): void
    {
        $update = $this->makeMentionUpdate(1, 'Hey @wtf_happened_bot what happened?');

        self::assertNotEmpty($update->effectiveMessage->entities);
        self::assertSame('mention', $update->effectiveMessage->entities[0]->type);
    }

    public function testSerializedUpdateCanBeParsedByPrepareContext(): void
    {
        $update = $this->makeGroupUpdate(1, 'Test message', 'alice', 'Alice');
        $encoded = $this->serializeUpdate($update);
        $decoded = json_decode($encoded, true);

        // Verify the structure prepareContext expects
        self::assertArrayHasKey('message', $decoded);
        self::assertArrayHasKey('from', $decoded['message']);
        self::assertArrayHasKey('text', $decoded['message']);
        self::assertArrayHasKey('date', $decoded['message']);
        self::assertSame('alice', $decoded['message']['from']['username']);
        self::assertSame('Test message', $decoded['message']['text']);
    }

    // -------------------------------------------------------------------------
    // Tests: prepareContext with realistic factory updates
    // -------------------------------------------------------------------------

    public function testPrepareContextWithMultipleUsers(): void
    {
        // Simulate a realistic group conversation
        $updates = [
            $this->makeGroupUpdate(1, 'Hey guys!', 'alice', 'Alice'),
            $this->makeGroupUpdate(2, 'Whats up', 'bob', 'Bob'),
            $this->makeGroupUpdate(3, 'Working on the project', 'charlie', 'Charlie'),
            $this->makeGroupUpdate(4, '@wtf_happened_bot summarize please', 'alice', 'Alice'),
        ];

        $records = [];
        foreach (array_reverse($updates) as $update) {
            $records[] = new UpdateRecord(
                updateId: $update->updateId,
                update: $this->serializeUpdate($update),
                chatId: self::CHAT_ID,
            );
        }

        $repo = $this->makeFakeRepo($records);

        $orm = Mockery::mock(ORMInterface::class);
        $orm->shouldReceive('getRepository')->andReturn($repo);

        $api = Mockery::mock(ApiInterface::class);

        $activity = new RouterActivity($orm, $api);
        $items = $activity->prepareContext(self::CHAT_ID);

        self::assertCount(4, $items);
        // Chronological order (reversed from DESC)
        self::assertStringContainsString('@alice', $items[0]['user']);
        self::assertSame('Hey guys!', $items[0]['text']);
        self::assertStringContainsString('@bob', $items[1]['user']);
        self::assertStringContainsString('@charlie', $items[2]['user']);
        self::assertStringContainsString('@wtf_happened_bot', $items[3]['text']);
    }

    public function testPrepareContextWithBotCommand(): void
    {
        $update = $this->makeCommandUpdate(1, '/wtf last 2 hours', 'alice');

        $records = [
            new UpdateRecord(
                updateId: $update->updateId,
                update: $this->serializeUpdate($update),
                chatId: self::CHAT_ID,
            ),
        ];

        $repo = $this->makeFakeRepo($records);

        $orm = Mockery::mock(ORMInterface::class);
        $orm->shouldReceive('getRepository')->andReturn($repo);

        $api = Mockery::mock(ApiInterface::class);

        $activity = new RouterActivity($orm, $api);
        $items = $activity->prepareContext(self::CHAT_ID);

        self::assertCount(1, $items);
        self::assertSame('/wtf last 2 hours', $items[0]['text']);
    }

    // -------------------------------------------------------------------------
    // Tests: Workflow ID generation with realistic updates
    // -------------------------------------------------------------------------

    public function testRealisticConversationWorkflowIdStability(): void
    {
        // Multiple messages in the same chat should produce the same workflow ID
        $update1 = $this->makeGroupUpdate(1, 'Hello', 'alice');
        $update2 = $this->makeGroupUpdate(2, 'Hi there', 'bob');
        $update3 = $this->makeMentionUpdate(3, '@wtf_happened_bot help', 'charlie');

        $id1 = RouterWorkflowHandler::generateWorkflowId($update1);
        $id2 = RouterWorkflowHandler::generateWorkflowId($update2);
        $id3 = RouterWorkflowHandler::generateWorkflowId($update3);

        // All messages in the same chat should produce the same workflow ID
        self::assertSame($id1, $id2);
        self::assertSame($id2, $id3);
    }

    public function testTopicConversationsSeparateWorkflows(): void
    {
        $generalUpdate = $this->makeGroupUpdate(1, 'In general', 'alice');
        $topic42Update = $this->makeGroupUpdate(2, 'In topic 42', 'bob', messageThreadId: 42);
        $topic99Update = $this->makeGroupUpdate(3, 'In topic 99', 'charlie', messageThreadId: 99);

        $generalId = RouterWorkflowHandler::generateWorkflowId($generalUpdate);
        $topic42Id = RouterWorkflowHandler::generateWorkflowId($topic42Update);
        $topic99Id = RouterWorkflowHandler::generateWorkflowId($topic99Update);

        // Each topic gets a unique workflow
        self::assertNotSame($generalId, $topic42Id);
        self::assertNotSame($generalId, $topic99Id);
        self::assertNotSame($topic42Id, $topic99Id);
    }

    // -------------------------------------------------------------------------
    // Tests: Context message building from factory updates
    // -------------------------------------------------------------------------

    public function testContextMessageFormatsCorrectly(): void
    {
        $updates = [
            $this->makeGroupUpdate(1, 'First', 'alice', 'Alice'),
            $this->makeGroupUpdate(2, 'Second', 'bob', 'Bob'),
        ];

        $records = [];
        foreach (array_reverse($updates) as $update) {
            $records[] = new UpdateRecord(
                updateId: $update->updateId,
                update: $this->serializeUpdate($update),
                chatId: self::CHAT_ID,
            );
        }

        $repo = $this->makeFakeRepo($records);
        $orm = Mockery::mock(ORMInterface::class);
        $orm->shouldReceive('getRepository')->andReturn($repo);
        $api = Mockery::mock(ApiInterface::class);

        $activity = new RouterActivity($orm, $api);
        $items = $activity->prepareContext(self::CHAT_ID);

        // Each item has the expected structure
        foreach ($items as $item) {
            self::assertArrayHasKey('date', $item);
            self::assertArrayHasKey('user', $item);
            self::assertArrayHasKey('text', $item);
            self::assertArrayHasKey('imageUrl', $item);
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
