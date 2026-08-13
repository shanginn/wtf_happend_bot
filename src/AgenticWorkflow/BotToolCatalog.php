<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

use Bot\Llm\Tools\Chat\GetCurrentTimeExecutor;
use Bot\Llm\Tools\Chat\SearchMessagesExecutor;
use Bot\Llm\Tools\Runtime\ListRuntimeCapabilitiesExecutor;
use Bot\Llm\Tools\Runtime\RuntimeToolExecutor;
use Bot\Llm\Tools\Runtime\SetRuntimeCapabilityStatusExecutor;
use Bot\Llm\Tools\Runtime\UpsertRuntimeSkillExecutor;
use Bot\Llm\Tools\Runtime\UpsertRuntimeToolExecutor;
use Bot\Llm\Tools\Search\InternetSearchExecutor;
use Bot\Llm\Tools\Telegram\TelegramApiCallExecutor;
use Bot\Llm\Tools\Telegram\TelegramApiSchemaExecutor;
use Bot\Memory\ParticipantMemoryStore;
use Bot\Space\Tools\SpaceCapsuleExecutor;
use Bot\Space\Tools\SpaceMemoryMutationAuthority;
use Bot\Space\Tools\SpaceMemoryToolStore;
use Bot\Telegram\TelegramChatAuthorizationPolicy;
use Closure;
use PiPHP\Agent\CancellationToken;
use PiPHP\Agent\Enum\ToolExecutionMode;
use PiPHP\Agent\Tool\AgentToolResult;
use PiPHP\Agent\Tool\ToolRegistry;
use PiPHP\AI\Content\TextContent;
use PiPHP\AI\Tool\Tool;
use PiPHP\Temporal\Serialization\PiPayloadCodec;
use PiPHP\Temporal\Tool\DurableAgentTool;
use PiPHP\Temporal\Tool\DurableToolExecutionContext;
use UnexpectedValueException;

final readonly class BotToolCatalog
{
    public function __construct(
        private ParticipantMemoryStore $memoryStore,
        private SearchMessagesExecutor $searchMessages,
        private InternetSearchExecutor $internetSearch,
        private GetCurrentTimeExecutor $currentTime,
        private TelegramApiSchemaExecutor $telegramSchema,
        private TelegramApiCallExecutor $telegramCall,
        private ListRuntimeCapabilitiesExecutor $listRuntimeCapabilities,
        private UpsertRuntimeSkillExecutor $upsertRuntimeSkill,
        private UpsertRuntimeToolExecutor $upsertRuntimeTool,
        private SetRuntimeCapabilityStatusExecutor $setRuntimeCapabilityStatus,
        private RuntimeToolExecutor $runtimeTool,
        private ?SpaceMemoryToolStore $spaceMemoryStore = null,
        private ?SpaceCapsuleExecutor $spaceCapsules = null,
        private ?TelegramChatAuthorizationPolicy $spaceMemoryAuthorization = null,
    ) {}

    /**
     * @return list<string>
     */
    public static function toolNames(): array
    {
        return array_map(
            static fn (Tool $tool): string => $tool->name,
            self::definitions(),
        );
    }

    /**
     * @return list<Tool>
     */
    public static function definitions(): array
    {
        return [
            self::tool(
                'stay_silent',
                'Finish this run without sending anything to Telegram.',
                [],
            ),
            self::tool(
                'save_memory',
                'Save one durable, evidence-backed fact about a chat participant.',
                [
                    'user_identifier' => self::string(
                        'Immutable participant reference from message metadata, such as telegram_user:123.',
                        maximum: 80,
                    ),
                    'memory' => self::string('Durable fact in one sentence.', maximum: 1000),
                    'quote'  => self::string(
                        'Exact supporting quote from the target participant in the current Telegram batch.',
                        maximum: 1000,
                    ),
                    'context' => self::string('Brief surrounding context.', maximum: 2000),
                ],
                ['user_identifier', 'memory', 'quote', 'context'],
            ),
            self::tool(
                'recall_memory',
                'Recall saved participant memories for the current chat.',
                [
                    'user_identifier' => self::nullableString('Optional immutable participant reference.', 80),
                    'query'           => self::nullableString('Optional search text.', 1000),
                    'limit'           => self::integer('Maximum results.', 1, 20),
                ],
            ),
            self::tool(
                'update_memory',
                'Replace one existing participant memory with corrected evidence.',
                [
                    'memory' => self::string('Corrected durable fact.', maximum: 1000),
                    'quote'  => self::string(
                        'Exact supporting quote from the current Telegram batch.',
                        maximum: 1000,
                    ),
                    'context'         => self::string('Brief surrounding context.', maximum: 2000),
                    'memory_id'       => self::nullableMemoryId('Preferred memory id from recall_memory.'),
                    'user_identifier' => self::nullableString('Optional immutable participant reference.', 80),
                    'current_memory'  => self::nullableString('Optional exact current fact.', 1000),
                    'query'           => self::nullableString('Optional narrow selector.', 1000),
                ],
                ['memory', 'quote', 'context'],
            ),
            self::tool(
                'forget_memory',
                'Delete saved participant memories from the current chat.',
                [
                    'memory_id'                  => self::nullableMemoryId('Preferred memory id from recall_memory.'),
                    'user_identifier'            => self::nullableString('Optional immutable participant reference.', 80),
                    'query'                      => self::nullableString('Optional narrow selector.', 1000),
                    'forget_all_for_participant' => self::boolean(
                        'Use only for an explicit request to forget every memory for a participant.',
                    ),
                    'quote' => self::string(
                        'Exact quote from the current Telegram batch requesting this deletion.',
                        maximum: 1000,
                    ),
                ],
                ['quote'],
            ),
            self::tool(
                'search_messages',
                'Search persisted inbound Telegram updates or load recent inbound history.',
                [
                    'query' => self::string(
                        'Search text; empty loads recent history.',
                        minimum: 0,
                        maximum: 1000,
                    ),
                    'username' => self::nullableString('Optional immutable participant filter.', 80),
                    'limit'    => self::integer('Maximum results.', 1, 30),
                ],
            ),
            self::tool(
                'internet_search',
                'Search the public internet through the configured SearXNG instance.',
                [
                    'query'      => self::string('Search query.', maximum: 1000),
                    'limit'      => self::integer('Maximum web results.', 1, 10),
                    'time_range' => [
                        'type' => ['string', 'null'],
                        'enum' => ['day', 'month', 'year', null],
                    ],
                    'language'    => self::string('Language code or auto.', maximum: 32),
                    'categories'  => self::string('Comma-separated SearXNG categories.', maximum: 100),
                    'safe_search' => self::integer('0 off, 1 moderate, 2 strict.', 0, 2),
                ],
                ['query'],
            ),
            self::tool(
                'get_current_time',
                'Get the current date and time in an IANA timezone.',
                ['timezone' => self::string(
                    'IANA timezone, for example Asia/Yekaterinburg.',
                    maximum: 128,
                )],
            ),
            self::tool(
                'telegram_api_schema',
                'Inspect available Telegram Bot API methods and parameter signatures.',
                [
                    'method' => self::nullableString('Exact method name.', 128),
                    'query'  => self::nullableString('Method search text.', 300),
                    'limit'  => self::integer('Maximum matching methods.', 1, 80),
                ],
            ),
            self::tool(
                'telegram_api_call',
                'Call a Telegram Bot API method bound to the current chat and topic.',
                [
                    'method'     => self::string('Telegram Bot API method name.', maximum: 128),
                    'parameters' => [
                        'type'                 => 'object',
                        'description'          => 'Method parameters in snake_case or camelCase.',
                        'additionalProperties' => true,
                    ],
                ],
                ['method'],
            ),
            self::tool(
                'list_runtime_capabilities',
                'List chat-scoped runtime skills and generated runtime tools.',
                [
                    'kind'             => ['type' => 'string', 'enum' => ['all', 'skill', 'tool']],
                    'include_disabled' => self::boolean('Include disabled capabilities.'),
                    'limit'            => self::integer('Maximum capabilities in this page.', 1, 20),
                    'offset'           => self::integer('Zero-based pagination offset.', 0, 1000),
                ],
            ),
            self::tool(
                'upsert_runtime_skill',
                'Create or update a durable chat-specific behavior instruction.',
                [
                    'name'        => self::string('Stable skill name.', maximum: 64),
                    'description' => self::string('When the skill applies.', maximum: 500),
                    'body'        => self::string('Full durable instructions.', maximum: 8000),
                    'enabled'     => self::boolean('Enable immediately.'),
                ],
                ['name', 'description', 'body'],
            ),
            self::tool(
                'upsert_runtime_tool',
                'Create or update a prompt-executed, chat-scoped runtime tool.',
                [
                    'name'              => self::string('Stable function name.', maximum: 64),
                    'description'       => self::string('Function description for the agent.', maximum: 500),
                    'parameters_schema' => [
                        'type'                 => 'object',
                        'description'          => 'Portable JSON Schema with an object root.',
                        'additionalProperties' => true,
                    ],
                    'instructions' => self::string(
                        'Execution instructions using only JSON arguments.',
                        maximum: 8000,
                    ),
                    'enabled' => self::boolean('Enable immediately.'),
                ],
                ['name', 'description', 'parameters_schema', 'instructions'],
            ),
            self::tool(
                'set_runtime_capability_status',
                'Enable or disable a chat-scoped runtime skill or tool.',
                [
                    'kind'    => ['type' => 'string', 'enum' => ['skill', 'tool']],
                    'name'    => self::string('Capability name.', maximum: 64),
                    'enabled' => self::boolean('Desired status.'),
                ],
                ['kind', 'name', 'enabled'],
            ),
            self::tool(
                'run_runtime_tool',
                'Execute one enabled generated runtime tool by name.',
                [
                    'name'      => self::string('Runtime tool name.', maximum: 64),
                    'arguments' => [
                        'type'                 => 'object',
                        'description'          => 'Arguments validated against the stored runtime schema.',
                        'additionalProperties' => true,
                    ],
                ],
                ['name'],
            ),
            self::tool(
                'run_space_capsule',
                'Run one executable capsule from the pinned Space release inside a network-denied Firecracker VM.',
                [
                    'name'      => self::string('Capsule name listed in the Space prompt.', maximum: 64),
                    'arguments' => [
                        'type'                 => 'object',
                        'description'          => 'JSON arguments passed to the isolated capsule on stdin.',
                        'additionalProperties' => true,
                    ],
                ],
                ['name'],
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function wireDefinitions(): array
    {
        $codec = new PiPayloadCodec();

        return array_map(
            static function (Tool $tool) use ($codec): array {
                $wire = $codec->toolToWire($tool);
                $mode = self::executionMode($tool->name);
                if ($mode !== null) {
                    $wire['executionMode'] = $mode->value;
                }

                return $wire;
            },
            self::definitions(),
        );
    }

    /**
     * @param list<string>|null $allowedNames
     */
    public function registry(?array $allowedNames = null): ToolRegistry
    {
        $allowed = $allowedNames === null ? null : array_fill_keys($allowedNames, true);
        $tools   = [];
        foreach (self::definitions() as $definition) {
            if ($allowed !== null && !isset($allowed[$definition->name])) {
                continue;
            }
            $tools[] = $this->implementation($definition);
        }

        return new ToolRegistry($tools);
    }

    private static function result(string $text, bool $terminate = false): AgentToolResult
    {
        return new AgentToolResult(
            content: [new TextContent($text)],
            terminate: $terminate,
        );
    }

    private static function executionMode(string $toolName): ?ToolExecutionMode
    {
        return in_array($toolName, [
            'stay_silent',
            'save_memory',
            'update_memory',
            'forget_memory',
            'telegram_api_call',
            'upsert_runtime_skill',
            'upsert_runtime_tool',
            'set_runtime_capability_status',
        ], true)
            ? ToolExecutionMode::Sequential
            : null;
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string>                        $required
     * @param string                              $name
     * @param string                              $description
     */
    private static function tool(
        string $name,
        string $description,
        array $properties,
        array $required = [],
    ): Tool {
        $parameters = [
            'type'                 => 'object',
            'properties'           => $properties,
            'additionalProperties' => false,
        ];
        if ($required !== []) {
            $parameters['required'] = $required;
        }

        return new Tool($name, $description, $parameters);
    }

    /**
     * @param string $description
     * @param int    $minimum
     * @param int    $maximum
     *
     * @return array<string, mixed>
     */
    private static function string(
        string $description,
        int $minimum = 1,
        int $maximum = 1000,
    ): array {
        return [
            'type'        => 'string',
            'description' => $description,
            'minLength'   => $minimum,
            'maxLength'   => $maximum,
        ];
    }

    /**
     * @param string $description
     * @param int    $maximum
     *
     * @return array<string, mixed>
     */
    private static function nullableString(string $description, int $maximum = 1000): array
    {
        return [
            'type'        => ['string', 'null'],
            'description' => $description,
            'minLength'   => 1,
            'maxLength'   => $maximum,
        ];
    }

    /**
     * @param string $description
     * @param int    $minimum
     * @param ?int   $maximum
     *
     * @return array<string, mixed>
     */
    private static function integer(string $description, int $minimum, ?int $maximum = null): array
    {
        return array_filter([
            'type'        => 'integer',
            'description' => $description,
            'minimum'     => $minimum,
            'maximum'     => $maximum,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    private static function nullableMemoryId(string $description): array
    {
        return [
            'type'        => ['string', 'integer', 'null'],
            'description' => $description,
        ];
    }

    /**
     * @param string $description
     *
     * @return array<string, mixed>
     */
    private static function boolean(string $description): array
    {
        return ['type' => 'boolean', 'description' => $description];
    }

    /**
     * @param array<string, mixed> $arguments
     * @param string               $key
     */
    private static function requiredString(array $arguments, string $key): string
    {
        $value = $arguments[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new UnexpectedValueException("{$key} must be a non-empty string.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param string               $key
     * @param string               $default
     */
    private static function stringValue(array $arguments, string $key, string $default = ''): string
    {
        $value = $arguments[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param string               $key
     */
    private static function nullableStringValue(array $arguments, string $key): ?string
    {
        $value = $arguments[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param string               $key
     * @param int                  $default
     */
    private static function integerValue(array $arguments, string $key, int $default): int
    {
        $value = $arguments[$key] ?? $default;

        return is_int($value) ? $value : $default;
    }

    /** @param array<string, mixed> $arguments */
    private static function nullableMemoryIdValue(array $arguments, string $key): null|int|string
    {
        $value = $arguments[$key] ?? null;

        return is_int($value) || is_string($value) ? $value : null;
    }

    private static function metadataString(
        DurableToolExecutionContext $context,
        string $key,
    ): ?string {
        $value = $context->metadata[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param string               $key
     * @param bool                 $default
     */
    private static function booleanValue(array $arguments, string $key, bool $default = false): bool
    {
        $value = $arguments[$key] ?? $default;

        return is_bool($value) ? $value : $default;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param string               $key
     *
     * @return array<string, mixed>
     */
    private static function arrayValue(array $arguments, string $key): array
    {
        $value = $arguments[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    private static function nullableMetadataInteger(
        DurableToolExecutionContext $context,
        string $key,
    ): ?int {
        $value = $context->metadata[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    private static function metadataInteger(
        DurableToolExecutionContext $context,
        string $key,
    ): int {
        $value = $context->metadata[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new UnexpectedValueException("PiPH tool context is missing integer {$key}.");
    }

    private function spaceMemoryAuthority(
        DurableToolExecutionContext $context,
    ): SpaceMemoryMutationAuthority {
        if ($this->spaceMemoryAuthorization === null) {
            throw new UnexpectedValueException('Space memory authorization is not configured.');
        }

        return SpaceMemoryMutationAuthority::fromMetadata(
            $context->metadata,
            $this->spaceMemoryAuthorization,
        );
    }

    private function implementation(Tool $tool): DurableAgentTool
    {
        return match ($tool->name) {
            'stay_silent' => $this->durable($tool, static fn (): AgentToolResult => self::result(
                'The agent deliberately stayed silent.',
                terminate: true,
            )),
            'save_memory' => $this->durable(
                $tool,
                function (int $chatId, array $args, DurableToolExecutionContext $context): AgentToolResult {
                    $spaceId = self::metadataString($context, 'spaceId');
                    $text    = $spaceId !== null && $this->spaceMemoryStore !== null
                        ? $this->spaceMemoryStore->save(
                            spaceId: $spaceId,
                            userIdentifier: self::requiredString($args, 'user_identifier'),
                            memory: self::requiredString($args, 'memory'),
                            quote: self::requiredString($args, 'quote'),
                            context: self::requiredString($args, 'context'),
                            idempotencyKey: $context->idempotencyKey,
                            authority: $this->spaceMemoryAuthority($context),
                        )
                        : $this->memoryStore->save(
                            chatId: $chatId,
                            userIdentifier: self::requiredString($args, 'user_identifier'),
                            memory: self::requiredString($args, 'memory'),
                            quote: self::requiredString($args, 'quote'),
                            context: self::requiredString($args, 'context'),
                        );

                    return self::result($text);
                },
                self::executionMode($tool->name),
            ),
            'recall_memory' => $this->durable($tool, function (
                int $chatId,
                array $args,
                DurableToolExecutionContext $context,
            ): AgentToolResult {
                $spaceId = self::metadataString($context, 'spaceId');
                $text    = $spaceId !== null && $this->spaceMemoryStore !== null
                    ? $this->spaceMemoryStore->recall(
                        spaceId: $spaceId,
                        userIdentifier: self::nullableStringValue($args, 'user_identifier'),
                        query: self::nullableStringValue($args, 'query'),
                        limit: self::integerValue($args, 'limit', 10),
                        atRevision: self::metadataInteger($context, 'memoryRevision'),
                    )
                    : $this->memoryStore->recall(
                        chatId: $chatId,
                        userIdentifier: self::nullableStringValue($args, 'user_identifier'),
                        query: self::nullableStringValue($args, 'query'),
                        limit: self::integerValue($args, 'limit', 10),
                    );

                return self::result($text);
            }),
            'update_memory' => $this->durable(
                $tool,
                function (int $chatId, array $args, DurableToolExecutionContext $context): AgentToolResult {
                    $spaceId  = self::metadataString($context, 'spaceId');
                    $memoryId = self::nullableMemoryIdValue($args, 'memory_id');
                    if ($spaceId !== null && $this->spaceMemoryStore !== null) {
                        $text = $this->spaceMemoryStore->update(
                            spaceId: $spaceId,
                            memory: self::requiredString($args, 'memory'),
                            quote: self::requiredString($args, 'quote'),
                            context: self::requiredString($args, 'context'),
                            memoryId: $memoryId === null ? null : (string) $memoryId,
                            userIdentifier: self::nullableStringValue($args, 'user_identifier'),
                            currentMemory: self::nullableStringValue($args, 'current_memory'),
                            query: self::nullableStringValue($args, 'query'),
                            idempotencyKey: $context->idempotencyKey,
                            authority: $this->spaceMemoryAuthority($context),
                            atRevision: self::metadataInteger($context, 'memoryRevision'),
                        );
                    } else {
                        $text = $this->memoryStore->update(
                            chatId: $chatId,
                            memory: self::requiredString($args, 'memory'),
                            quote: self::requiredString($args, 'quote'),
                            context: self::requiredString($args, 'context'),
                            memoryId: is_int($memoryId)
                                ? $memoryId
                                : (is_string($memoryId) && ctype_digit($memoryId) ? (int) $memoryId : null),
                            userIdentifier: self::nullableStringValue($args, 'user_identifier'),
                            currentMemory: self::nullableStringValue($args, 'current_memory'),
                            query: self::nullableStringValue($args, 'query'),
                        );
                    }

                    return self::result($text);
                },
                self::executionMode($tool->name),
            ),
            'forget_memory' => $this->durable(
                $tool,
                function (int $chatId, array $args, DurableToolExecutionContext $context): AgentToolResult {
                    $spaceId  = self::metadataString($context, 'spaceId');
                    $memoryId = self::nullableMemoryIdValue($args, 'memory_id');
                    if ($spaceId !== null && $this->spaceMemoryStore !== null) {
                        $text = $this->spaceMemoryStore->forget(
                            spaceId: $spaceId,
                            memoryId: $memoryId === null ? null : (string) $memoryId,
                            userIdentifier: self::nullableStringValue($args, 'user_identifier'),
                            query: self::nullableStringValue($args, 'query'),
                            forgetAllForParticipant: self::booleanValue($args, 'forget_all_for_participant'),
                            evidenceQuote: self::requiredString($args, 'quote'),
                            idempotencyKey: $context->idempotencyKey,
                            authority: $this->spaceMemoryAuthority($context),
                            atRevision: self::metadataInteger($context, 'memoryRevision'),
                        );
                    } else {
                        $text = $this->memoryStore->forget(
                            chatId: $chatId,
                            memoryId: is_int($memoryId)
                                ? $memoryId
                                : (is_string($memoryId) && ctype_digit($memoryId) ? (int) $memoryId : null),
                            userIdentifier: self::nullableStringValue($args, 'user_identifier'),
                            query: self::nullableStringValue($args, 'query'),
                            forgetAllForParticipant: self::booleanValue($args, 'forget_all_for_participant'),
                        );
                    }

                    return self::result($text);
                },
                self::executionMode($tool->name),
            ),
            'search_messages' => $this->durable($tool, function (
                int $chatId,
                array $args,
                DurableToolExecutionContext $context,
            ): AgentToolResult {
                $parameters = [
                    'queryText'    => self::stringValue($args, 'query'),
                    'usernameText' => self::nullableStringValue($args, 'username'),
                    'resultLimit'  => self::integerValue($args, 'limit', 10),
                ];
                // A Telegram Space is the whole chat. Topic ID is only the
                // route for the current reply and must not narrow memory or
                // history retrieval to one thread.
                $text = $this->searchMessages->execute(
                    chatId: $chatId,
                    queryText: $parameters['queryText'],
                    usernameText: $parameters['usernameText'],
                    resultLimit: $parameters['resultLimit'],
                );

                return self::result($text);
            }),
            'internet_search' => $this->durable($tool, fn (int $_chatId, array $args): AgentToolResult => self::result(
                $this->internetSearch->execute(
                    query: self::requiredString($args, 'query'),
                    limit: self::integerValue($args, 'limit', 5),
                    timeRange: self::nullableStringValue($args, 'time_range'),
                    language: self::stringValue($args, 'language', 'auto'),
                    categories: self::stringValue($args, 'categories', 'general'),
                    safeSearch: self::integerValue($args, 'safe_search', 1),
                ),
            )),
            'get_current_time' => $this->durable($tool, fn (int $_chatId, array $args): AgentToolResult => self::result(
                $this->currentTime->execute(self::stringValue($args, 'timezone', 'UTC')),
            )),
            'telegram_api_schema' => $this->durable($tool, fn (int $_chatId, array $args): AgentToolResult => self::result(
                $this->telegramSchema->execute(
                    methodName: self::nullableStringValue($args, 'method'),
                    query: self::nullableStringValue($args, 'query'),
                    limit: self::integerValue($args, 'limit', 20),
                ),
            )),
            'telegram_api_call' => $this->durable(
                $tool,
                function (
                    int $chatId,
                    array $args,
                    DurableToolExecutionContext $context,
                ): AgentToolResult {
                    $method = self::requiredString($args, 'method');
                    $text   = $this->telegramCall->execute(
                        chatId: $chatId,
                        methodName: $method,
                        parameters: self::arrayValue($args, 'parameters'),
                        messageThreadId: self::nullableMetadataInteger($context, 'topicId'),
                    );

                    return self::result(
                        $text,
                        terminate: TelegramApiCallExecutor::isTerminalMethod($method)
                            && TelegramApiCallExecutor::isSuccessfulResult($text),
                    );
                },
                self::executionMode($tool->name),
            ),
            'list_runtime_capabilities' => $this->durable($tool, fn (int $chatId, array $args): AgentToolResult => self::result(
                $this->listRuntimeCapabilities->execute(
                    chatId: $chatId,
                    kind: self::stringValue($args, 'kind', 'all'),
                    includeDisabled: self::booleanValue($args, 'include_disabled'),
                    limit: self::integerValue($args, 'limit', 20),
                    offset: self::integerValue($args, 'offset', 0),
                ),
            )),
            'upsert_runtime_skill' => $this->durable(
                $tool,
                function (int $chatId, array $args): AgentToolResult {
                    $text = $this->upsertRuntimeSkill->execute(
                        chatId: $chatId,
                        name: self::requiredString($args, 'name'),
                        description: self::requiredString($args, 'description'),
                        body: self::requiredString($args, 'body'),
                        enabled: self::booleanValue($args, 'enabled', true),
                    );

                    return self::result($text);
                },
                self::executionMode($tool->name),
            ),
            'upsert_runtime_tool' => $this->durable(
                $tool,
                function (int $chatId, array $args): AgentToolResult {
                    $text = $this->upsertRuntimeTool->execute(
                        chatId: $chatId,
                        name: self::requiredString($args, 'name'),
                        description: self::requiredString($args, 'description'),
                        parametersSchema: self::arrayValue($args, 'parameters_schema'),
                        instructions: self::requiredString($args, 'instructions'),
                        enabled: self::booleanValue($args, 'enabled', true),
                    );

                    return self::result($text);
                },
                self::executionMode($tool->name),
            ),
            'set_runtime_capability_status' => $this->durable(
                $tool,
                fn (int $chatId, array $args): AgentToolResult => self::result(
                    $this->setRuntimeCapabilityStatus->execute(
                        chatId: $chatId,
                        kind: self::requiredString($args, 'kind'),
                        name: self::requiredString($args, 'name'),
                        enabled: self::booleanValue($args, 'enabled'),
                    ),
                ),
                self::executionMode($tool->name),
            ),
            'run_runtime_tool' => $this->durable($tool, fn (
                int $chatId,
                array $args,
                DurableToolExecutionContext $context,
            ): AgentToolResult => self::result(
                $this->runtimeTool->execute(
                    chatId: $chatId,
                    toolName: self::requiredString($args, 'name'),
                    arguments: self::arrayValue($args, 'arguments'),
                    idempotencyKey: $context->idempotencyKey,
                ),
            )),
            'run_space_capsule' => $this->durable($tool, function (
                int $_chatId,
                array $args,
                DurableToolExecutionContext $context,
            ): AgentToolResult {
                if ($this->spaceCapsules === null) {
                    return self::result('Space capsule execution is not configured on this worker.');
                }
                $spaceId = self::metadataString($context, 'spaceId')
                    ?? throw new UnexpectedValueException('Space capsule context is missing spaceId.');
                $releaseId = self::metadataString($context, 'releaseId')
                    ?? throw new UnexpectedValueException('Space capsule context is missing releaseId.');
                $releaseDigest = self::metadataString($context, 'releaseDigest')
                    ?? throw new UnexpectedValueException('Space capsule context is missing releaseDigest.');

                return self::result($this->spaceCapsules->execute(
                    spaceId: $spaceId,
                    releaseId: $releaseId,
                    releaseDigest: $releaseDigest,
                    name: self::requiredString($args, 'name'),
                    arguments: self::arrayValue($args, 'arguments'),
                    idempotencyKey: $context->idempotencyKey,
                ));
            }),
            default => throw new UnexpectedValueException("No implementation for tool {$tool->name}."),
        };
    }

    /**
     * @param Closure(int, array<string, mixed>, DurableToolExecutionContext): AgentToolResult $handler
     * @param Tool                                                                             $tool
     * @param ?ToolExecutionMode                                                               $mode
     */
    private function durable(
        Tool $tool,
        Closure $handler,
        ?ToolExecutionMode $mode = null,
    ): DurableAgentTool {
        return new DurableAgentTool(
            tool: $tool,
            label: $tool->name,
            handler: function (
                DurableToolExecutionContext $context,
                CancellationToken $_cancellation,
                ?Closure $_onUpdate,
            ) use ($handler): AgentToolResult {
                $chatId = $context->metadata['chatId'] ?? null;
                if (!is_int($chatId)) {
                    throw new UnexpectedValueException('PiPH tool context is missing an integer chatId.');
                }

                return $handler($chatId, $context->arguments, $context);
            },
            mode: $mode,
        );
    }
}
