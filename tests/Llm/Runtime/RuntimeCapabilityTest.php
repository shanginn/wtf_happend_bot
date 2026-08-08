<?php

declare(strict_types=1);

namespace Tests\Llm\Runtime;

use Bot\Entity\RuntimeSkill;
use Bot\Entity\RuntimeTool;
use Bot\Llm\Runtime\RuntimeCapabilityMutationLock;
use Bot\Llm\Runtime\RuntimeCapabilityRegistry;
use Bot\Llm\Runtime\RuntimeSkillDefinition;
use Bot\Llm\Runtime\RuntimeCapabilityValidator;
use Bot\Llm\Tools\Runtime\ListRuntimeCapabilitiesExecutor;
use Bot\Llm\Tools\Runtime\RuntimeToolExecutor;
use Bot\Llm\Tools\Runtime\SetRuntimeCapabilityStatusExecutor;
use Bot\Llm\Tools\Runtime\UpsertRuntimeSkillExecutor;
use Bot\Llm\Tools\Runtime\UpsertRuntimeToolExecutor;
use Cycle\Database\DatabaseInterface;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\RepositoryInterface;
use Mockery;
use PiPHP\AI\Codec\JsonObjectNormalizer;
use PiPHP\Temporal\Contract\ModelCompletionGatewayInterface;
use stdClass;
use Tests\TestCase;
use UnexpectedValueException;

final class RuntimeCapabilityTest extends TestCase
{
    public function testUpsertRuntimeSkillCreatesNormalizedDefinition(): void
    {
        $state = (object) ['skills' => [], 'tools' => []];
        $repo = $this->makeRuntimeSkillRepo($state);
        $orm = Mockery::mock(ORMInterface::class);
        $orm->shouldReceive('getRepository')->with(RuntimeSkill::class)->andReturn($repo);
        $orm
            ->shouldReceive('getRepository')
            ->with(RuntimeTool::class)
            ->andReturn($this->makeRuntimeToolRepo($state));

        $result = (new UpsertRuntimeSkillExecutor($orm))->execute(
            -100123,
            name: 'Incident Style',
            description: 'Keeps incident replies terse.',
            body: 'Use terse incident-response phrasing.',
        );

        self::assertSame('Runtime skill "incident_style" created and is enabled.', $result);
        self::assertCount(1, $state->skills);
        self::assertSame('incident_style', $state->skills[0]->name);
    }

    public function testUpsertRuntimeSkillUpdatesDefinition(): void
    {
        $skill = new RuntimeSkill(
            chatId: -100123,
            name: 'incident_style',
            description: 'Old description.',
            body: 'Old body.',
        );
        $skill->id = 1;

        $state = (object) ['skills' => [$skill], 'tools' => []];
        $repo = $this->makeRuntimeSkillRepo($state);
        $orm = Mockery::mock(ORMInterface::class);
        $orm->shouldReceive('getRepository')->with(RuntimeSkill::class)->andReturn($repo);
        $orm
            ->shouldReceive('getRepository')
            ->with(RuntimeTool::class)
            ->andReturn($this->makeRuntimeToolRepo($state));

        $result = (new UpsertRuntimeSkillExecutor($orm))->execute(
            -100123,
            name: 'incident_style',
            description: 'New description.',
            body: 'New body.',
            enabled: false,
        );

        self::assertSame('Runtime skill "incident_style" updated and is disabled.', $result);
        self::assertCount(1, $state->skills);
        self::assertSame('New description.', $state->skills[0]->description);
        self::assertSame('New body.', $state->skills[0]->body);
        self::assertFalse($state->skills[0]->enabled);
    }

    public function testUpsertRuntimeToolCreatesNormalizedDbDefinition(): void
    {
        $state = (object) ['skills' => [], 'tools' => []];
        $repo = $this->makeRuntimeToolRepo($state);

        $orm = Mockery::mock(ORMInterface::class);
        $orm->shouldReceive('getRepository')->with(RuntimeTool::class)->andReturn($repo);
        $orm
            ->shouldReceive('getRepository')
            ->with(RuntimeSkill::class)
            ->andReturn($this->makeRuntimeSkillRepo($state));

        $result = (new UpsertRuntimeToolExecutor($orm))->execute(
            -100123,
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
        );

        self::assertSame('Runtime tool "format_incident" created and is enabled.', $result);
        self::assertCount(1, $state->tools);
        self::assertSame('format_incident', $state->tools[0]->name);
        self::assertSame('Return terse bullets.', $state->tools[0]->instructions);

        $schema = json_decode($state->tools[0]->parametersSchema, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('object', $schema['type']);
        self::assertFalse($schema['additionalProperties']);
    }

    public function testEmptyRuntimeToolSchemaCannotPoisonModelToolRegistration(): void
    {
        $state = (object) ['skills' => [], 'tools' => []];
        $repo  = $this->makeRuntimeToolRepo($state);

        $orm = Mockery::mock(ORMInterface::class);
        $orm->shouldReceive('getRepository')->with(RuntimeTool::class)->andReturn($repo);
        $orm
            ->shouldReceive('getRepository')
            ->with(RuntimeSkill::class)
            ->andReturn($this->makeRuntimeSkillRepo($state));

        $result = (new UpsertRuntimeToolExecutor($orm))->execute(
            -1001593195299,
            name: 'iddqd_dildak',
            description: 'Regression fixture for an empty parameter schema.',
            parametersSchema: [],
            instructions: 'Return a short response.',
        );

        self::assertSame('Runtime tool "iddqd_dildak" created and is enabled.', $result);
        self::assertCount(1, $state->tools);

        $storedSchema = RuntimeCapabilityValidator::decodeParametersSchema(
            $state->tools[0]->parametersSchema,
        );
        $wireSchema = JsonObjectNormalizer::schema($storedSchema);
        $wire = json_decode(json_encode([
            'type'     => 'function',
            'function' => [
                'name'       => 'iddqd_dildak',
                'parameters' => $wireSchema,
            ],
        ], \JSON_THROW_ON_ERROR), false, flags: \JSON_THROW_ON_ERROR);

        self::assertInstanceOf(stdClass::class, $wire);
        self::assertInstanceOf(stdClass::class, $wire->function ?? null);
        self::assertInstanceOf(stdClass::class, $wire->function->parameters ?? null);
        self::assertInstanceOf(stdClass::class, $wire->function->parameters->properties ?? null);
        self::assertSame('object', $wire->function->parameters->type ?? null);
    }

    public function testRuntimeRegistryReturnsValidatedSkillDefinitions(): void
    {
        $skill = new RuntimeSkill(
            chatId: -100123,
            name: 'memory-management',
            description: 'Runtime memory policy.',
            body: 'Use the runtime memory policy.',
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

        $orm = Mockery::mock(ORMInterface::class);
        $orm->shouldReceive('getRepository')->with(RuntimeSkill::class)->andReturn($skillRepo);

        $registry = new RuntimeCapabilityRegistry($orm);
        $skills = $registry->runtimeSkillsForChat(-100123);

        self::assertCount(1, $skills);
        self::assertInstanceOf(RuntimeSkillDefinition::class, $skills[0]);
        self::assertSame('memory-management', $skills[0]->name);
        self::assertSame('Use the runtime memory policy.', $skills[0]->body);
    }

    public function testStoredRuntimeSchemaFailsClosedWhenJsonIsMalformed(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('not valid JSON');

        RuntimeCapabilityValidator::decodeParametersSchema('{"type":"object"');
    }

    public function testListReturnsPaginatedFullContentAndReportsCorruptSchemas(): void
    {
        $skill = new RuntimeSkill(
            chatId: -100123,
            name: 'incident_style',
            description: 'Keeps replies terse.',
            body: 'Full skill body.',
            updatedAt: 300,
        );
        $validTool = new RuntimeTool(
            chatId: -100123,
            name: 'format_incident',
            description: 'Formats facts.',
            parametersSchema: '{"type":"object","properties":{}}',
            instructions: 'Full tool instructions.',
            updatedAt: 100,
        );
        $corruptTool = new RuntimeTool(
            chatId: -100123,
            name: 'broken_tool',
            description: 'Has corrupt storage.',
            parametersSchema: '{"type":',
            instructions: 'Do not execute.',
            updatedAt: 200,
        );
        $state = (object) [
            'skills' => [$skill],
            'tools' => [$validTool, $corruptTool],
        ];
        $orm = Mockery::mock(ORMInterface::class);
        $orm
            ->shouldReceive('getRepository')
            ->with(RuntimeSkill::class)
            ->andReturn($this->makeRuntimeSkillRepo($state));
        $orm
            ->shouldReceive('getRepository')
            ->with(RuntimeTool::class)
            ->andReturn($this->makeRuntimeToolRepo($state));

        $json = (new ListRuntimeCapabilitiesExecutor($orm))->execute(
            chatId: -100123,
            includeDisabled: true,
            limit: 2,
        );
        $payload = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame('Full skill body.', $payload['skills'][0]['body']);
        self::assertSame('broken_tool', $payload['tools'][0]['name']);
        self::assertSame('Do not execute.', $payload['tools'][0]['instructions']);
        self::assertNull($payload['tools'][0]['parameters_schema']);
        self::assertStringContainsString('not valid JSON', $payload['tools'][0]['schema_error']);
        self::assertSame(
            ['limit' => 2, 'offset' => 0, 'total' => 3, 'has_more' => true],
            $payload['pagination'],
        );

        $nextJson = (new ListRuntimeCapabilitiesExecutor($orm))->execute(
            chatId: -100123,
            includeDisabled: true,
            limit: 2,
            offset: 2,
        );
        $next = json_decode($nextJson, true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame('format_incident', $next['tools'][0]['name']);
        self::assertSame('Full tool instructions.', $next['tools'][0]['instructions']);
        self::assertFalse($next['pagination']['has_more']);
    }

    public function testRuntimeToolWithCorruptStoredSchemaReturnsInBandError(): void
    {
        $tool = new RuntimeTool(
            chatId: -100123,
            name: 'broken_tool',
            description: 'Has corrupt storage.',
            parametersSchema: 'not-json',
            instructions: 'Do not execute.',
        );
        $state = (object) ['skills' => [], 'tools' => [$tool]];
        $orm = Mockery::mock(ORMInterface::class);
        $orm
            ->shouldReceive('getRepository')
            ->with(RuntimeTool::class)
            ->andReturn($this->makeRuntimeToolRepo($state));
        $models = Mockery::mock(ModelCompletionGatewayInterface::class);
        $models->shouldNotReceive('complete');

        $result = (new RuntimeToolExecutor($orm, $models))->execute(
            chatId: -100123,
            toolName: 'broken_tool',
            arguments: [],
            idempotencyKey: 'tool-call-1',
        );

        self::assertSame(
            'Runtime tool "broken_tool" is unavailable: stored schema is invalid.',
            $result,
        );
    }

    public function testLegacyArrayRuntimeSchemaReturnsInBandErrorWithoutModelCall(): void
    {
        $tool = new RuntimeTool(
            chatId: -1001593195299,
            name: 'iddqd_dildak',
            description: 'Historical malformed schema fixture.',
            parametersSchema: '[]',
            instructions: 'Do not execute.',
        );
        $state = (object) ['skills' => [], 'tools' => [$tool]];
        $orm   = Mockery::mock(ORMInterface::class);
        $orm
            ->shouldReceive('getRepository')
            ->with(RuntimeTool::class)
            ->andReturn($this->makeRuntimeToolRepo($state));
        $models = Mockery::mock(ModelCompletionGatewayInterface::class);
        $models->shouldNotReceive('complete');

        $result = (new RuntimeToolExecutor($orm, $models))->execute(
            chatId: -1001593195299,
            toolName: 'iddqd_dildak',
            arguments: [],
            idempotencyKey: 'incident-2026-08-07',
        );

        self::assertSame(
            'Runtime tool "iddqd_dildak" is unavailable: stored schema is invalid.',
            $result,
        );
    }

    public function testRuntimeToolWithInvalidStoredInstructionsReturnsInBandError(): void
    {
        $tool = new RuntimeTool(
            chatId: -100123,
            name: 'broken_tool',
            description: 'Has invalid storage.',
            parametersSchema: '{"type":"object","properties":{}}',
            instructions: '',
        );
        $state = (object) ['skills' => [], 'tools' => [$tool]];
        $orm = Mockery::mock(ORMInterface::class);
        $orm
            ->shouldReceive('getRepository')
            ->with(RuntimeTool::class)
            ->andReturn($this->makeRuntimeToolRepo($state));
        $models = Mockery::mock(ModelCompletionGatewayInterface::class);
        $models->shouldNotReceive('complete');

        $result = (new RuntimeToolExecutor($orm, $models))->execute(
            chatId: -100123,
            toolName: 'broken_tool',
            arguments: [],
            idempotencyKey: 'tool-call-1',
        );

        self::assertSame(
            'Runtime tool "broken_tool" is unavailable: stored definition is invalid: '
                . 'Runtime tool instructions cannot be empty.',
            $result,
        );
    }

    public function testCapabilityMutationLockUsesPostgresTransactionScopedAdvisoryLock(): void
    {
        $orm = Mockery::mock(ORMInterface::class);
        $database = Mockery::mock(DatabaseInterface::class);
        $database->shouldReceive('getType')->once()->andReturn('Postgres');
        $database
            ->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(
                static fn(callable $callback): mixed => $callback($database),
            );
        $database
            ->shouldReceive('execute')
            ->once()
            ->withArgs(static fn(string $sql, array $parameters): bool =>
                str_contains($sql, 'pg_advisory_xact_lock')
                && $parameters === [-100123])
            ->andReturn(0);

        $mutations = 0;
        $result = (new RuntimeCapabilityMutationLock($orm, $database))->synchronized(
            -100123,
            static function () use (&$mutations): string {
                ++$mutations;

                return 'mutated';
            },
        );

        self::assertSame('mutated', $result);
        self::assertSame(1, $mutations);
    }

    public function testUpsertsEnforcePerKindCountLimits(): void
    {
        $skills = [];
        $tools = [];
        for ($index = 0; $index < RuntimeCapabilityValidator::MAX_CAPABILITIES_PER_KIND; ++$index) {
            $skills[] = new RuntimeSkill(
                chatId: -100123,
                name: 'skill_' . $index,
                description: 'd',
                body: 'b',
                enabled: false,
            );
            $tools[] = new RuntimeTool(
                chatId: -100123,
                name: 'tool_' . $index,
                description: 'd',
                parametersSchema: '{"type":"object","properties":{}}',
                instructions: 'i',
                enabled: false,
            );
        }

        $skillState = (object) ['skills' => $skills, 'tools' => []];
        $skillOrm = Mockery::mock(ORMInterface::class);
        $skillOrm
            ->shouldReceive('getRepository')
            ->with(RuntimeSkill::class)
            ->andReturn($this->makeRuntimeSkillRepo($skillState));

        self::assertSame(
            'A chat can store at most 20 runtime skills.',
            (new UpsertRuntimeSkillExecutor($skillOrm))->execute(
                -100123,
                'one_more',
                'description',
                'body',
            ),
        );

        $toolState = (object) ['skills' => [], 'tools' => $tools];
        $toolOrm = Mockery::mock(ORMInterface::class);
        $toolOrm
            ->shouldReceive('getRepository')
            ->with(RuntimeTool::class)
            ->andReturn($this->makeRuntimeToolRepo($toolState));

        self::assertSame(
            'A chat can store at most 20 runtime tools.',
            (new UpsertRuntimeToolExecutor($toolOrm))->execute(
                -100123,
                'one_more',
                'description',
                [],
                'instructions',
            ),
        );
    }

    public function testSkillUpsertEnforcesCombinedEnabledByteBudget(): void
    {
        $tools = [];
        for ($index = 0; $index < 6; ++$index) {
            $tools[] = new RuntimeTool(
                chatId: -100123,
                name: 'tool_' . $index,
                description: 'd',
                parametersSchema: '{"type":"object","properties":{}}',
                instructions: str_repeat('i', 7900),
            );
        }

        $state = (object) ['skills' => [], 'tools' => $tools];
        $orm = Mockery::mock(ORMInterface::class);
        $orm
            ->shouldReceive('getRepository')
            ->with(RuntimeSkill::class)
            ->andReturn($this->makeRuntimeSkillRepo($state));
        $orm
            ->shouldReceive('getRepository')
            ->with(RuntimeTool::class)
            ->andReturn($this->makeRuntimeToolRepo($state));

        $result = (new UpsertRuntimeSkillExecutor($orm))->execute(
            -100123,
            'too_much',
            'description',
            str_repeat('b', 3000),
        );

        self::assertStringContainsString('exceeding the per-chat limit of 50000 bytes', $result);
        self::assertSame([], $state->skills);
    }

    public function testToolUpsertEnforcesCombinedEnabledByteBudget(): void
    {
        $skills = [];
        for ($index = 0; $index < 6; ++$index) {
            $skills[] = new RuntimeSkill(
                chatId: -100123,
                name: 'skill_' . $index,
                description: 'd',
                body: str_repeat('b', 7900),
            );
        }

        $state = (object) ['skills' => $skills, 'tools' => []];
        $orm = Mockery::mock(ORMInterface::class);
        $orm
            ->shouldReceive('getRepository')
            ->with(RuntimeTool::class)
            ->andReturn($this->makeRuntimeToolRepo($state));
        $orm
            ->shouldReceive('getRepository')
            ->with(RuntimeSkill::class)
            ->andReturn($this->makeRuntimeSkillRepo($state));

        $result = (new UpsertRuntimeToolExecutor($orm))->execute(
            -100123,
            'too_much',
            'description',
            [],
            str_repeat('i', 3000),
        );

        self::assertStringContainsString('exceeding the per-chat limit of 50000 bytes', $result);
        self::assertSame([], $state->tools);
    }

    public function testUpsertsRejectOversizedGeneratedContent(): void
    {
        $orm = Mockery::mock(ORMInterface::class);

        self::assertSame(
            'Skill body must be at most 8000 bytes; received 8001 bytes.',
            (new UpsertRuntimeSkillExecutor($orm))->execute(
                -100123,
                'oversized',
                'description',
                str_repeat('b', RuntimeCapabilityValidator::MAX_SKILL_BODY_BYTES + 1),
            ),
        );
        self::assertSame(
            'Runtime tool instructions must be at most 8000 bytes; received 8001 bytes.',
            (new UpsertRuntimeToolExecutor($orm))->execute(
                -100123,
                'oversized',
                'description',
                [],
                str_repeat('i', RuntimeCapabilityValidator::MAX_TOOL_INSTRUCTIONS_BYTES + 1),
            ),
        );

        $schema = [
            'type' => 'object',
            'properties' => [
                'value' => [
                    'type' => 'string',
                    'description' => str_repeat('s', RuntimeCapabilityValidator::MAX_PARAMETERS_SCHEMA_BYTES),
                ],
            ],
        ];
        $schemaResult = (new UpsertRuntimeToolExecutor($orm))->execute(
            -100123,
            'oversized_schema',
            'description',
            $schema,
            'instructions',
        );

        self::assertStringContainsString('parameters_schema must be at most 8000 bytes', $schemaResult);
    }

    public function testStatusCannotEnableOversizedLegacyCapability(): void
    {
        $skill = new RuntimeSkill(
            chatId: -100123,
            name: 'legacy_oversized',
            description: 'description',
            body: str_repeat('b', RuntimeCapabilityValidator::MAX_SKILL_BODY_BYTES + 1),
            enabled: false,
        );
        $state = (object) ['skills' => [$skill], 'tools' => []];
        $orm = Mockery::mock(ORMInterface::class);
        $orm
            ->shouldReceive('getRepository')
            ->with(RuntimeSkill::class)
            ->andReturn($this->makeRuntimeSkillRepo($state));

        $result = (new SetRuntimeCapabilityStatusExecutor($orm))->execute(
            -100123,
            'skill',
            'legacy_oversized',
            true,
        );

        self::assertStringContainsString('cannot be enabled', $result);
        self::assertStringContainsString('at most 8000 bytes', $result);
        self::assertFalse($skill->enabled);
    }

    public function testStatusCannotBypassCombinedEnabledByteBudget(): void
    {
        $skills = [];
        for ($index = 0; $index < 6; ++$index) {
            $skills[] = new RuntimeSkill(
                chatId: -100123,
                name: 'skill_' . $index,
                description: 'd',
                body: str_repeat('b', 7900),
            );
        }
        $tool = new RuntimeTool(
            chatId: -100123,
            name: 'disabled_tool',
            description: 'description',
            parametersSchema: '{"type":"object","properties":{}}',
            instructions: str_repeat('i', 3000),
            enabled: false,
        );
        $state = (object) ['skills' => $skills, 'tools' => [$tool]];
        $orm = Mockery::mock(ORMInterface::class);
        $orm
            ->shouldReceive('getRepository')
            ->with(RuntimeTool::class)
            ->andReturn($this->makeRuntimeToolRepo($state));
        $orm
            ->shouldReceive('getRepository')
            ->with(RuntimeSkill::class)
            ->andReturn($this->makeRuntimeSkillRepo($state));

        $result = (new SetRuntimeCapabilityStatusExecutor($orm))->execute(
            -100123,
            'tool',
            'disabled_tool',
            true,
        );

        self::assertStringContainsString('exceeding the per-chat limit of 50000 bytes', $result);
        self::assertFalse($tool->enabled);
    }

    public function testRuntimeRegistryBoundsLegacySkillContext(): void
    {
        $skills = [
            new RuntimeSkill(
                chatId: -100123,
                name: 'invalid',
                description: 'description',
                body: str_repeat('b', RuntimeCapabilityValidator::MAX_SKILL_BODY_BYTES + 1),
            ),
        ];
        for ($index = 0; $index < 7; ++$index) {
            $skills[] = new RuntimeSkill(
                chatId: -100123,
                name: 'skill_' . $index,
                description: 'd',
                body: str_repeat('b', 7900),
            );
        }

        $state = (object) ['skills' => $skills, 'tools' => []];
        $orm = Mockery::mock(ORMInterface::class);
        $orm
            ->shouldReceive('getRepository')
            ->with(RuntimeSkill::class)
            ->andReturn($this->makeRuntimeSkillRepo($state));

        $definitions = (new RuntimeCapabilityRegistry($orm))->runtimeSkillsForChat(-100123);
        $bytes = array_sum(array_map(
            static fn (RuntimeSkillDefinition $skill): int =>
                RuntimeCapabilityValidator::runtimeSkillBytes(
                    $skill->name,
                    $skill->description,
                    $skill->body,
                ),
            $definitions,
        ));

        self::assertCount(6, $definitions);
        self::assertLessThanOrEqual(RuntimeCapabilityValidator::MAX_ENABLED_BYTES_PER_CHAT, $bytes);
        self::assertNotContains('invalid', array_column($definitions, 'name'));
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
                return array_values(array_filter(
                    $this->state->skills,
                    static fn (RuntimeSkill $skill): bool =>
                        $skill->chatId === $chatId && (!$enabledOnly || $skill->enabled),
                ));
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

    private function makeRuntimeToolRepo(object $state): RepositoryInterface
    {
        return new class($state) implements RepositoryInterface {
            public function __construct(private object $state) {}

            public function findByChatId(int $chatId, bool $enabledOnly = true): array
            {
                return array_values(array_filter(
                    $this->state->tools,
                    static fn (RuntimeTool $tool): bool =>
                        $tool->chatId === $chatId && (!$enabledOnly || $tool->enabled),
                ));
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
                $tool = $this->findByName($chatId, $name);

                return $tool?->enabled ? $tool : null;
            }

            public function save(RuntimeTool $tool, bool $run = true): void
            {
                if (!isset($tool->id)) {
                    $tool->id = count($this->state->tools) + 1;
                    $this->state->tools[] = $tool;
                    return;
                }

                foreach ($this->state->tools as $index => $existing) {
                    if ($existing->id === $tool->id) {
                        $this->state->tools[$index] = $tool;
                        return;
                    }
                }

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
    }
}
