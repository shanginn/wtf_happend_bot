<?php

declare(strict_types=1);

namespace Tests\RouterWorkflow;

use Bot\Entity\UpdateRecord;
use Bot\RouterWorkflow\RouterActivity;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\RepositoryInterface;
use Mockery;
use Phenogram\Bindings\ApiInterface;
use Phenogram\Bindings\Factories\ChatFactory;
use Phenogram\Bindings\Factories\MessageFactory;
use Phenogram\Bindings\Factories\UpdateFactory;
use Phenogram\Bindings\Factories\UserFactory;
use Phenogram\Bindings\Serializer;
use Tests\TestCase;

class PrepareContextTest extends TestCase
{
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

    private function makeUpdateRecord(int $updateId, array $overrides = []): UpdateRecord
    {
        $defaults = [
            'chatId' => -100999,
            'username' => 'alice',
            'firstName' => 'Alice',
            'text' => 'Hello',
            'date' => 1709870400,
        ];

        $opts = array_merge($defaults, $overrides);

        $update = UpdateFactory::make(
            updateId: $updateId,
            message: MessageFactory::make(
                messageId: $updateId,
                chat: ChatFactory::make(id: $opts['chatId'], type: 'supergroup'),
                from: UserFactory::make(
                    id: $updateId * 10,
                    username: $opts['username'],
                    firstName: $opts['firstName'],
                    isBot: false,
                ),
                text: $opts['text'],
                date: $opts['date'],
            ),
        );

        $serializer = new Serializer();
        $encoded = json_encode($serializer->serialize([$update])[0]);

        return new UpdateRecord(
            updateId: $updateId,
            update: $encoded,
            chatId: $opts['chatId'],
        );
    }

    public function testPrepareContextExtractsUserAndText(): void
    {
        $records = [
            $this->makeUpdateRecord(2, ['username' => 'bob', 'firstName' => 'Bob', 'text' => 'Second message']),
            $this->makeUpdateRecord(1, ['username' => 'alice', 'firstName' => 'Alice', 'text' => 'First message']),
        ];

        $repo = $this->makeFakeRepo($records);

        $orm = Mockery::mock(ORMInterface::class);
        $orm->shouldReceive('getRepository')
            ->with(UpdateRecord::class)
            ->andReturn($repo);

        $api = Mockery::mock(ApiInterface::class);

        $activity = new RouterActivity($orm, $api);
        $items = $activity->prepareContext(-100999);

        // Records are reversed to chronological order
        self::assertCount(2, $items);
        self::assertStringContainsString('@alice', $items[0]['user']);
        self::assertSame('First message', $items[0]['text']);
        self::assertStringContainsString('@bob', $items[1]['user']);
        self::assertSame('Second message', $items[1]['text']);
    }

    public function testPrepareContextFormatsDate(): void
    {
        $records = [
            $this->makeUpdateRecord(1, ['date' => 1709870400, 'text' => 'Test']),
        ];

        $repo = $this->makeFakeRepo($records);

        $orm = Mockery::mock(ORMInterface::class);
        $orm->shouldReceive('getRepository')->andReturn($repo);

        $api = Mockery::mock(ApiInterface::class);

        $activity = new RouterActivity($orm, $api);
        $items = $activity->prepareContext(-100999);

        self::assertCount(1, $items);
        // Date should be formatted as Y-m-d H:i
        self::assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $items[0]['date']);
    }

    public function testPrepareContextReturnsEmptyForNoRecords(): void
    {
        $repo = $this->makeFakeRepo([]);

        $orm = Mockery::mock(ORMInterface::class);
        $orm->shouldReceive('getRepository')->andReturn($repo);

        $api = Mockery::mock(ApiInterface::class);

        $activity = new RouterActivity($orm, $api);
        $items = $activity->prepareContext(-100999);

        self::assertSame([], $items);
    }

    public function testPrepareContextHandlesUsernameWithName(): void
    {
        $records = [
            $this->makeUpdateRecord(1, ['username' => 'john', 'firstName' => 'John', 'text' => 'Hi']),
        ];

        $repo = $this->makeFakeRepo($records);

        $orm = Mockery::mock(ORMInterface::class);
        $orm->shouldReceive('getRepository')->andReturn($repo);

        $api = Mockery::mock(ApiInterface::class);

        $activity = new RouterActivity($orm, $api);
        $items = $activity->prepareContext(-100999);

        // User string should contain both @username and first name
        self::assertStringContainsString('@john', $items[0]['user']);
        self::assertStringContainsString('John', $items[0]['user']);
    }

    public function testPrepareContextImageUrlIsNull(): void
    {
        $records = [
            $this->makeUpdateRecord(1, ['text' => 'No image']),
        ];

        $repo = $this->makeFakeRepo($records);

        $orm = Mockery::mock(ORMInterface::class);
        $orm->shouldReceive('getRepository')->andReturn($repo);

        $api = Mockery::mock(ApiInterface::class);

        $activity = new RouterActivity($orm, $api);
        $items = $activity->prepareContext(-100999);

        self::assertNull($items[0]['imageUrl']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
