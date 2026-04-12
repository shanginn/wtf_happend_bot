<?php

declare(strict_types=1);

namespace Tests\Memory;

use Bot\Entity\ParticipantMemory;
use Bot\Llm\Tools\Memory\RecallMemory;
use Bot\Llm\Tools\Memory\SaveMemory;
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
            new SaveMemory(
                userIdentifier: '@Alice',
                memory: 'Alice prefers TypeScript over PHP',
                quote: 'I would choose TypeScript for this one',
                context: 'The group was discussing which stack to use for a new project.',
            ),
        );

        self::assertSame('Memory saved for @Alice: Alice prefers TypeScript over PHP', $result);
        self::assertCount(1, $state->saved);
        self::assertSame('@alice', $state->saved[0]->participantKey);
        self::assertSame('Alice prefers TypeScript over PHP', $state->saved[0]->memory);
        self::assertSame('I would choose TypeScript for this one', $state->saved[0]->quote);
        self::assertSame('The group was discussing which stack to use for a new project.', $state->saved[0]->context);
    }

    public function testSaveUpdatesExistingMemoryByAttribute(): void
    {
        $memory = new ParticipantMemory(
            chatId: -100123,
            participantKey: '@alice',
            participantLabel: '@alice',
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
            new SaveMemory(
                userIdentifier: '@alice',
                memory: 'Alice uses Vim',
                quote: 'Actually I switched to Neovim last year',
                context: 'They corrected their earlier statement while discussing tooling.',
            ),
        );

        self::assertSame('Memory updated for @alice: Alice uses Vim', $result);
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
            new RecallMemory(
                userIdentifier: '@alice',
                query: 'Temporal Symfony',
            ),
        );

        self::assertStringContainsString('Memories for @alice:', $result);
        self::assertStringContainsString('memory: Alice works mostly with Symfony and Temporal', $result);
        self::assertStringContainsString('quote: Most of my work is Symfony and Temporal these days', $result);
        self::assertStringNotContainsString('@bob', $result);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
