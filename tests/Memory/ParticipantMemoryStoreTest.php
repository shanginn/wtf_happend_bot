<?php

declare(strict_types=1);

namespace Tests\Memory;

use Bot\Entity\ParticipantMemory;
use Bot\Memory\ParticipantMemoryStore;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\RepositoryInterface;
use Mockery;
use Tests\TestCase;

class ParticipantMemoryStoreTest extends TestCase
{
    public function testSaveCreatesMemoryAndNormalizesFields(): void
    {
        $state = (object) ['saved' => []];
        $repo = new class($state) implements RepositoryInterface {
            public function __construct(private object $state) {}

            public function findExact(int $chatId, string $participantKey, string $memoryText): ?ParticipantMemory
            {
                foreach ($this->state->saved as $savedMemory) {
                    if (
                        $savedMemory->chatId === $chatId
                        && $savedMemory->participantKey === $participantKey
                        && $savedMemory->memory === $memoryText
                    ) {
                        return $savedMemory;
                    }
                }

                return null;
            }

            public function findByParticipantKey(int $chatId, string $participantKey): array
            {
                return array_values(array_filter(
                    $this->state->saved,
                    static fn (ParticipantMemory $memory): bool => $memory->chatId === $chatId
                        && $memory->participantKey === $participantKey,
                ));
            }

            public function findByChatId(int $chatId): array
            {
                return array_values(array_filter(
                    $this->state->saved,
                    static fn (ParticipantMemory $memory): bool => $memory->chatId === $chatId,
                ));
            }

            public function save(ParticipantMemory $memory, bool $run = true): void
            {
                if (!isset($memory->id)) {
                    $memory->id = count($this->state->saved) + 1;
                }

                foreach ($this->state->saved as $index => $existing) {
                    if ($existing->id === $memory->id) {
                        $this->state->saved[$index] = $memory;
                        return;
                    }
                }

                $this->state->saved[] = $memory;
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

        $orm = Mockery::mock(ORMInterface::class);
        $orm->shouldReceive('getRepository')->andReturn($repo);

        $store = new ParticipantMemoryStore($orm);

        $result = $store->save(
            -100123,
            userIdentifier: 'telegram_user:7',
            memory: 'Alice prefers TypeScript over PHP',
            quote: 'I would choose TypeScript for this one',
            context: 'The group was discussing which stack to use for a new project.',
        );

        self::assertSame('Memory saved for telegram_user:7: Alice prefers TypeScript over PHP', $result);
        self::assertCount(1, $state->saved);
        self::assertSame('telegram_user:7', $state->saved[0]->participantKey);
        self::assertSame('Alice prefers TypeScript over PHP', $state->saved[0]->memory);
        self::assertSame('I would choose TypeScript for this one', $state->saved[0]->quote);
        self::assertSame('The group was discussing which stack to use for a new project.', $state->saved[0]->context);
    }

    public function testSaveUpdatesExistingMemoryByAttribute(): void
    {
        $memory = new ParticipantMemory(
            chatId: -100123,
            participantKey: 'telegram_user:7',
            participantLabel: 'telegram_user:7',
            memory: 'Alice uses Vim',
            quote: 'I use Vim',
            context: 'They were comparing editors.',
            createdAt: 10,
            updatedAt: 10,
        );
        $memory->id = 1;

        $state = (object) ['saved' => [$memory]];
        $repo = new class($state) implements RepositoryInterface {
            public function __construct(private object $state) {}

            public function findExact(int $chatId, string $participantKey, string $memoryText): ?ParticipantMemory
            {
                foreach ($this->state->saved as $savedMemory) {
                    if (
                        $savedMemory->chatId === $chatId
                        && $savedMemory->participantKey === $participantKey
                        && $savedMemory->memory === $memoryText
                    ) {
                        return $savedMemory;
                    }
                }

                return null;
            }

            public function findByParticipantKey(int $chatId, string $participantKey): array
            {
                return $this->state->saved;
            }

            public function findByChatId(int $chatId): array
            {
                return $this->state->saved;
            }

            public function save(ParticipantMemory $memory, bool $run = true): void
            {
                $this->state->saved[0] = $memory;
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

        $orm = Mockery::mock(ORMInterface::class);
        $orm->shouldReceive('getRepository')->andReturn($repo);

        $store = new ParticipantMemoryStore($orm);

        $result = $store->save(
            -100123,
            userIdentifier: 'telegram_user:7',
            memory: 'Alice uses Vim',
            quote: 'Actually I switched to Neovim last year',
            context: 'They corrected their earlier statement while discussing tooling.',
        );

        self::assertSame('Memory updated for telegram_user:7: Alice uses Vim', $result);
        self::assertSame('Alice uses Vim', $state->saved[0]->memory);
        self::assertSame('Actually I switched to Neovim last year', $state->saved[0]->quote);
        self::assertSame('They corrected their earlier statement while discussing tooling.', $state->saved[0]->context);
    }

    public function testRecallFiltersAcrossParticipantAndQuery(): void
    {
        $first = new ParticipantMemory(
            chatId: -100123,
            participantKey: '@alice',
            participantLabel: '@alice',
            memory: 'Alice works mostly with Symfony and Temporal',
            quote: 'Most of my work is Symfony and Temporal these days',
            context: 'They were describing their current backend work.',
            createdAt: 10,
            updatedAt: 20,
        );
        $first->id = 1;

        $second = new ParticipantMemory(
            chatId: -100123,
            participantKey: '@bob',
            participantLabel: '@bob',
            memory: 'Bob prefers VS Code',
            quote: 'VS Code is still my default editor',
            context: 'They were talking about editor setup.',
            createdAt: 10,
            updatedAt: 15,
        );
        $second->id = 2;

        $repo = new class($first, $second) implements RepositoryInterface {
            public function __construct(
                private ParticipantMemory $first,
                private ParticipantMemory $second,
            ) {}

            public function findExact(int $chatId, string $participantKey, string $memoryText): ?ParticipantMemory
            {
                return null;
            }

            public function findByParticipantKey(int $chatId, string $participantKey): array
            {
                return array_values(array_filter(
                    [$this->first, $this->second],
                    static fn (ParticipantMemory $memory): bool => $memory->participantKey === $participantKey,
                ));
            }

            public function findByChatId(int $chatId): array
            {
                return [$this->first, $this->second];
            }

            public function save(ParticipantMemory $memory, bool $run = true): void
            {
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

        $orm = Mockery::mock(ORMInterface::class);
        $orm->shouldReceive('getRepository')->andReturn($repo);

        $store = new ParticipantMemoryStore($orm);

        $result = $store->recall(
            -100123,
            userIdentifier: '@alice',
            query: 'Temporal Symfony',
        );

        self::assertStringContainsString('Memories for @alice:', $result);
        self::assertStringContainsString('memory: Alice works mostly with Symfony and Temporal', $result);
        self::assertStringContainsString('quote: Most of my work is Symfony and Temporal these days', $result);
        self::assertStringNotContainsString('@bob', $result);
    }

    public function testRecallIncludesMemoryIdsForFollowUpTools(): void
    {
        $memory = new ParticipantMemory(
            chatId: -100123,
            participantKey: '@alice',
            participantLabel: '@alice',
            memory: 'Alice owns deploys',
            quote: 'I own deploys',
            context: 'Release planning.',
            createdAt: 10,
            updatedAt: 20,
        );
        $memory->id = 7;

        [$store] = $this->makeStoreWithMemories($memory);

        $result = $store->recall(
            -100123,
            userIdentifier: '@alice',
        );

        self::assertStringContainsString('- #7 @alice | memory: Alice owns deploys', $result);
    }

    public function testUpdateReplacesSingleMemorySelectedById(): void
    {
        $memory = new ParticipantMemory(
            chatId: -100123,
            participantKey: '@alice',
            participantLabel: '@alice',
            memory: 'Alice uses Vim',
            quote: 'I use Vim',
            context: 'They were comparing editors.',
            createdAt: 10,
            updatedAt: 20,
        );
        $memory->id = 7;

        [$store, $state] = $this->makeStoreWithMemories($memory);

        $result = $store->update(
            -100123,
            memory: 'Alice uses Neovim',
            quote: 'I switched to Neovim',
            context: 'They corrected their editor preference.',
            memoryId: 7,
        );

        self::assertSame('Memory updated for @alice (#7): Alice uses Neovim', $result);
        self::assertSame('Alice uses Neovim', $state->saved[0]->memory);
        self::assertSame('I switched to Neovim', $state->saved[0]->quote);
        self::assertSame('They corrected their editor preference.', $state->saved[0]->context);
    }

    public function testUpdateRejectsAmbiguousSelector(): void
    {
        $first = new ParticipantMemory(
            chatId: -100123,
            participantKey: 'telegram_user:7',
            participantLabel: 'telegram_user:7',
            memory: 'Alice uses Symfony',
            quote: 'Symfony is my daily framework',
            context: 'Backend discussion.',
            createdAt: 10,
            updatedAt: 20,
        );
        $first->id = 1;
        $second = new ParticipantMemory(
            chatId: -100123,
            participantKey: 'telegram_user:7',
            participantLabel: 'telegram_user:7',
            memory: 'Alice maintains Symfony services',
            quote: 'I maintain our Symfony services',
            context: 'Ownership discussion.',
            createdAt: 10,
            updatedAt: 21,
        );
        $second->id = 2;

        [$store, $state] = $this->makeStoreWithMemories($first, $second);

        $result = $store->update(
            -100123,
            memory: 'Alice works on Laravel now',
            quote: 'I moved to Laravel',
            context: 'They corrected their backend work.',
            userIdentifier: 'telegram_user:7',
            query: 'Symfony',
        );

        self::assertStringContainsString('selector matched multiple memories', $result);
        self::assertStringContainsString('#1 telegram_user:7: Alice uses Symfony', $result);
        self::assertStringContainsString('#2 telegram_user:7: Alice maintains Symfony services', $result);
        self::assertSame('Alice uses Symfony', $state->saved[0]->memory);
        self::assertSame('Alice maintains Symfony services', $state->saved[1]->memory);
    }

    public function testForgetDeletesSingleMemoryById(): void
    {
        $first = new ParticipantMemory(
            chatId: -100123,
            participantKey: '@alice',
            participantLabel: '@alice',
            memory: 'Alice uses Vim',
            quote: 'I use Vim',
            context: 'Editor discussion.',
            createdAt: 10,
            updatedAt: 20,
        );
        $first->id = 1;
        $second = new ParticipantMemory(
            chatId: -100123,
            participantKey: '@bob',
            participantLabel: '@bob',
            memory: 'Bob prefers VS Code',
            quote: 'VS Code is my default',
            context: 'Editor discussion.',
            createdAt: 10,
            updatedAt: 21,
        );
        $second->id = 2;

        [$store, $state] = $this->makeStoreWithMemories($first, $second);

        $result = $store->forget(
            -100123,
            memoryId: 1,
        );

        self::assertSame('Memory forgotten for @alice (#1): Alice uses Vim', $result);
        self::assertCount(1, $state->saved);
        self::assertSame(2, $state->saved[0]->id);
    }

    public function testForgetAllForParticipantRequiresExplicitFlag(): void
    {
        $first = new ParticipantMemory(
            chatId: -100123,
            participantKey: 'telegram_user:7',
            participantLabel: 'telegram_user:7',
            memory: 'Alice uses Vim',
            quote: 'I use Vim',
            context: 'Editor discussion.',
            createdAt: 10,
            updatedAt: 20,
        );
        $first->id = 1;
        $second = new ParticipantMemory(
            chatId: -100123,
            participantKey: 'telegram_user:7',
            participantLabel: 'telegram_user:7',
            memory: 'Alice owns deploys',
            quote: 'I own deploys',
            context: 'Release planning.',
            createdAt: 10,
            updatedAt: 21,
        );
        $second->id = 2;

        [$store, $state] = $this->makeStoreWithMemories($first, $second);

        $result = $store->forget(
            -100123,
            userIdentifier: 'telegram_user:7',
        );

        self::assertSame(
            'Memory not forgotten: pass memory_id, a narrow query, or set forget_all_for_participant for an explicit broad deletion.',
            $result,
        );
        self::assertCount(2, $state->saved);

        $result = $store->forget(
            -100123,
            userIdentifier: 'telegram_user:7',
            forgetAllForParticipant: true,
        );

        self::assertSame('2 memories forgotten for telegram_user:7.', $result);
        self::assertSame([], $state->saved);
    }

    public function testSaveRejectsMutableParticipantAlias(): void
    {
        [$store, $state] = $this->makeStoreWithMemories();

        $result = $store->save(
            -100123,
            userIdentifier: '@alice',
            memory: 'Alice owns deploys',
            quote: 'I own deploys',
            context: 'Release planning.',
        );

        self::assertSame(
            'Memory not saved: participant reference must be telegram_user:<positive id> '
            . 'or telegram_chat:<non-zero signed id>.',
            $result,
        );
        self::assertSame([], $state->saved);
    }

    public function testParticipantScopedMutationsRejectMutableAlias(): void
    {
        $memory = new ParticipantMemory(
            chatId: -100123,
            participantKey: '@alice',
            participantLabel: '@alice',
            memory: 'Alice owns deploys',
            quote: 'I own deploys',
            context: 'Release planning.',
        );
        $memory->id = 7;

        [$store, $state] = $this->makeStoreWithMemories($memory);

        self::assertSame(
            'Memory not updated: participant reference must be telegram_user:<positive id> '
            . 'or telegram_chat:<non-zero signed id>.',
            $store->update(
                -100123,
                memory: 'Alice no longer owns deploys',
                quote: 'I handed deploys over',
                context: 'Ownership update.',
                userIdentifier: '@alice',
                currentMemory: 'Alice owns deploys',
            ),
        );
        self::assertSame(
            'Memory not forgotten: participant reference must be telegram_user:<positive id> '
            . 'or telegram_chat:<non-zero signed id>.',
            $store->forget(
                -100123,
                userIdentifier: '@alice',
                query: 'deploys',
            ),
        );
        self::assertCount(1, $state->saved);
        self::assertSame('Alice owns deploys', $state->saved[0]->memory);
    }

    /**
     * @return array{ParticipantMemoryStore, object{saved: array<ParticipantMemory>}}
     */
    private function makeStoreWithMemories(ParticipantMemory ...$memories): array
    {
        $state = (object) ['saved' => array_values($memories)];
        $repo = new class($state) implements RepositoryInterface {
            public function __construct(private object $state) {}

            public function findExact(int $chatId, string $participantKey, string $memoryText): ?ParticipantMemory
            {
                foreach ($this->state->saved as $savedMemory) {
                    if (
                        $savedMemory->chatId === $chatId
                        && $savedMemory->participantKey === $participantKey
                        && $savedMemory->memory === $memoryText
                    ) {
                        return $savedMemory;
                    }
                }

                return null;
            }

            public function findById(int $chatId, int $id): ?ParticipantMemory
            {
                foreach ($this->state->saved as $savedMemory) {
                    if ($savedMemory->chatId === $chatId && isset($savedMemory->id) && $savedMemory->id === $id) {
                        return $savedMemory;
                    }
                }

                return null;
            }

            public function findByParticipantKey(int $chatId, string $participantKey): array
            {
                return array_values(array_filter(
                    $this->state->saved,
                    static fn (ParticipantMemory $memory): bool => $memory->chatId === $chatId
                        && $memory->participantKey === $participantKey,
                ));
            }

            public function findByChatId(int $chatId): array
            {
                return array_values(array_filter(
                    $this->state->saved,
                    static fn (ParticipantMemory $memory): bool => $memory->chatId === $chatId,
                ));
            }

            public function save(ParticipantMemory $memory, bool $run = true): void
            {
                foreach ($this->state->saved as $index => $existing) {
                    if (isset($existing->id, $memory->id) && $existing->id === $memory->id) {
                        $this->state->saved[$index] = $memory;
                        return;
                    }
                }

                if (!isset($memory->id)) {
                    $memory->id = count($this->state->saved) + 1;
                }

                $this->state->saved[] = $memory;
            }

            public function delete(ParticipantMemory $memory, bool $run = true): void
            {
                $this->state->saved = array_values(array_filter(
                    $this->state->saved,
                    static fn (ParticipantMemory $savedMemory): bool => !isset($savedMemory->id, $memory->id)
                        || $savedMemory->id !== $memory->id,
                ));
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

        $orm = Mockery::mock(ORMInterface::class);
        $orm->shouldReceive('getRepository')->andReturn($repo);

        return [new ParticipantMemoryStore($orm), $state];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
