<?php

declare(strict_types=1);

namespace Tests\Llm\Runtime;

use Bot\Entity\RuntimeSkill;
use Bot\Entity\RuntimeTool;
use Bot\Llm\Runtime\RuntimeCapabilityRegistry;
use Bot\Llm\Runtime\RuntimeSkillDefinition;
use Bot\Llm\Runtime\RuntimeToolDefinition;
use Bot\Llm\Skills\MemoryManagementSkill;
use Bot\Llm\Tools\Chat\GetCurrentTime;
use Bot\Llm\Tools\Runtime\UpsertRuntimeSkill;
use Bot\Llm\Tools\Runtime\UpsertRuntimeSkillExecutor;
use Bot\Llm\Tools\Runtime\UpsertRuntimeTool;
use Bot\Llm\Tools\Runtime\UpsertRuntimeToolExecutor;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\RepositoryInterface;
use Mockery;
use Phenogram\Bindings\ApiInterface;
use Phenogram\Bindings\Types\Interfaces\MessageInterface;
use Tests\TestCase;

final class RuntimeCapabilityTest extends TestCase
{
    public function testUpsertRuntimeSkillNotifiesChatWhenCreated(): void
    {
        $state = (object) ['skills' => []];
        $repo = $this->makeRuntimeSkillRepo($state);
        $orm = Mockery::mock(ORMInterface::class);
        $orm->shouldReceive('getRepository')->with(RuntimeSkill::class)->andReturn($repo);

        $message = $this->createStub(MessageInterface::class);
        $api = $this->createMock(ApiInterface::class);
        $api
            ->expects($this->once())
            ->method('sendMessage')
            ->willReturnCallback(function (
                int|string $chatId,
                string $text,
            ) use ($message): MessageInterface {
                self::assertSame(-100123, $chatId);
                self::assertSame('Навык добавлен: incident_style', $text);

                return $message;
            });

        $result = (new UpsertRuntimeSkillExecutor($orm, $api))->execute(
            -100123,
            new UpsertRuntimeSkill(
                name: 'Incident Style',
                description: 'Keeps incident replies terse.',
                body: 'Use terse incident-response phrasing.',
            ),
        );

        self::assertSame('Runtime skill "incident_style" created and is enabled.', $result);
        self::assertCount(1, $state->skills);
        self::assertSame('incident_style', $state->skills[0]->name);
    }

    public function testUpsertRuntimeSkillNotifiesChatWhenUpdated(): void
    {
        $skill = new RuntimeSkill(
            chatId: -100123,
            name: 'incident_style',
            description: 'Old description.',
            body: 'Old body.',
        );
        $skill->id = 1;

        $state = (object) ['skills' => [$skill]];
        $repo = $this->makeRuntimeSkillRepo($state);
        $orm = Mockery::mock(ORMInterface::class);
        $orm->shouldReceive('getRepository')->with(RuntimeSkill::class)->andReturn($repo);

        $message = $this->createStub(MessageInterface::class);
        $api = $this->createMock(ApiInterface::class);
        $api
            ->expects($this->once())
            ->method('sendMessage')
            ->willReturnCallback(function (
                int|string $chatId,
                string $text,
            ) use ($message): MessageInterface {
                self::assertSame(-100123, $chatId);
                self::assertSame('Навык обновлён: incident_style', $text);

                return $message;
            });

        $result = (new UpsertRuntimeSkillExecutor($orm, $api))->execute(
            -100123,
            new UpsertRuntimeSkill(
                name: 'incident_style',
                description: 'New description.',
                body: 'New body.',
                enabled: false,
            ),
        );

        self::assertSame('Runtime skill "incident_style" updated and is disabled.', $result);
        self::assertCount(1, $state->skills);
        self::assertSame('New description.', $state->skills[0]->description);
        self::assertSame('New body.', $state->skills[0]->body);
        self::assertFalse($state->skills[0]->enabled);
    }

    public function testUpsertRuntimeToolCreatesNormalizedDbDefinition(): void
    {
        $state = (object) ['tools' => []];
        $repo = new class($state) implements RepositoryInterface {
            public function __construct(private object $state) {}

            public function findByChatId(int $chatId, bool $enabledOnly = true): array
            {
                return $this->state->tools;
            }

            public function findByName(int $chatId, string $name): ?RuntimeTool
            {
                foreach ($this->state->tools as $tool) {
                    if ($tool->chatId === $chatId && $tool->name === $name) {
                        return $tool;
                    }
                }

                return null;
            }

            public function findEnabledByName(int $chatId, string $name): ?RuntimeTool
            {
                return null;
            }

            public function save(RuntimeTool $tool, bool $run = true): void
            {
                $tool->id = count($this->state->tools) + 1;
                $this->state->tools[] = $tool;
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
        $orm->shouldReceive('getRepository')->with(RuntimeTool::class)->andReturn($repo);

        $message = $this->createStub(MessageInterface::class);
        $api = $this->createMock(ApiInterface::class);
        $api
            ->expects($this->once())
            ->method('sendMessage')
            ->willReturnCallback(function (
                int|string $chatId,
                string $text,
            ) use ($message): MessageInterface {
                self::assertSame(-100123, $chatId);
                self::assertSame('Инструмент добавлен: format_incident', $text);

                return $message;
            });

        $result = (new UpsertRuntimeToolExecutor($orm, $api))->execute(
            -100123,
            new UpsertRuntimeTool(
                name: 'Format Incident',
                description: 'Formats incident facts.',
                parametersSchema: [
                    'type' => 'object',
                    'properties' => [
                        'summary' => ['type' => 'string'],
                    ],
                    'required' => ['summary'],
                ],
                instructions: 'Return terse bullets.',
            ),
        );

        self::assertSame('Runtime tool "format_incident" created and is enabled.', $result);
        self::assertCount(1, $state->tools);
        self::assertSame('format_incident', $state->tools[0]->name);
        self::assertSame('Return terse bullets.', $state->tools[0]->instructions);

        $schema = json_decode($state->tools[0]->parametersSchema, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('object', $schema['type']);
        self::assertFalse($schema['additionalProperties']);
    }

    public function testRuntimeRegistryAddsToolsAndOverridesStaticSkills(): void
    {
        $skill = new RuntimeSkill(
            chatId: -100123,
            name: 'memory-management',
            description: 'Runtime memory policy.',
            body: 'Use the runtime memory policy.',
        );
        $tool = new RuntimeTool(
            chatId: -100123,
            name: 'format_incident',
            description: 'Formats incident facts.',
            parametersSchema: '{"type":"object","properties":{}}',
            instructions: 'Return terse bullets.',
        );

        $skillRepo = new class($skill) implements RepositoryInterface {
            public function __construct(private RuntimeSkill $skill) {}

            public function findByChatId(int $chatId, bool $enabledOnly = true): array
            {
                return [$this->skill];
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
        $toolRepo = new class($tool) implements RepositoryInterface {
            public function __construct(private RuntimeTool $tool) {}

            public function findByChatId(int $chatId, bool $enabledOnly = true): array
            {
                return [$this->tool];
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
        $orm->shouldReceive('getRepository')->with(RuntimeSkill::class)->andReturn($skillRepo);
        $orm->shouldReceive('getRepository')->with(RuntimeTool::class)->andReturn($toolRepo);

        $registry = new RuntimeCapabilityRegistry($orm);
        $skills = $registry->responseSkillsForChat(-100123, [MemoryManagementSkill::class]);
        $tools = $registry->responseToolsForChat(-100123, [GetCurrentTime::class]);

        self::assertCount(1, $skills);
        self::assertInstanceOf(RuntimeSkillDefinition::class, $skills[0]);
        self::assertSame('memory-management', $skills[0]->name);
        self::assertSame('Use the runtime memory policy.', $skills[0]->body);

        self::assertCount(2, $tools);
        self::assertSame(GetCurrentTime::class, $tools[0]);
        self::assertInstanceOf(RuntimeToolDefinition::class, $tools[1]);
        self::assertSame('format_incident', $tools[1]->name);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeRuntimeSkillRepo(object $state): RepositoryInterface
    {
        return new class($state) implements RepositoryInterface {
            public function __construct(private object $state) {}

            public function findByChatId(int $chatId, bool $enabledOnly = true): array
            {
                return $this->state->skills;
            }

            public function findByName(int $chatId, string $name): ?RuntimeSkill
            {
                foreach ($this->state->skills as $skill) {
                    if ($skill->chatId === $chatId && $skill->name === $name) {
                        return $skill;
                    }
                }

                return null;
            }

            public function save(RuntimeSkill $skill, bool $run = true): void
            {
                if (!isset($skill->id)) {
                    $skill->id = count($this->state->skills) + 1;
                    $this->state->skills[] = $skill;
                    return;
                }

                foreach ($this->state->skills as $index => $existing) {
                    if ($existing->id === $skill->id) {
                        $this->state->skills[$index] = $skill;
                        return;
                    }
                }

                $this->state->skills[] = $skill;
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
}
