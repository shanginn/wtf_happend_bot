<?php

declare(strict_types=1);

use Bot\Activity\TelegramActivity;
use Bot\AgenticWorkflow\AgentContextActivity;
use Bot\AgenticWorkflow\AgenticWorkflow;
use Bot\AgenticWorkflow\BotToolCatalog;
use Bot\AgenticWorkflow\CycleModelCompletionResultStore;
use Bot\AgenticWorkflow\IdempotentToolExecutionGateway;
use Bot\AgenticWorkflow\RuntimeCapabilityAuthorizationGateway;
use Bot\Config\TemporalConfig;
use Bot\Infrastructure\CycleORM\CycleOrmScope;
use Bot\Llm\Runtime\RuntimeCapabilityRegistry;
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
use Bot\Telegram\TelegramBindingsSerializer;
use Bot\Telegram\TelegramChatAuthorizationPolicy;
use Phenogram\Bindings\Api;
use Phenogram\Framework\TelegramBotApiClient;
use PiPHP\AI\Models;
use PiPHP\AI\Provider\OpenAICompatiblePreset;
use PiPHP\AI\Provider\ProviderRegistry;
use PiPHP\Temporal\Activity\DurableAgentActivities;
use PiPHP\Temporal\Gateway\ModelsGateway;
use PiPHP\Temporal\Gateway\ModelsModelResolver;
use PiPHP\Temporal\Gateway\ToolRegistryGateway;
use PiPHP\Temporal\Workflow\DurableAgentWorkflow;

/** @var TemporalConfig $temporalConfig */
$temporalConfig = require __DIR__ . '/temporal.php';

$telegramClient = new TelegramBotApiClient($temporalConfig->botToken);
$telegramSerializer = new TelegramBindingsSerializer();
$telegramApi = new Api(
    client: $telegramClient,
    serializer: $telegramSerializer,
);
$telegramAuthorization = new TelegramChatAuthorizationPolicy($telegramApi);

$ormData = (static fn (): array => require __DIR__ . '/orm.php')();
/** @var CycleOrmScope $ormScope */
$ormScope = $ormData[2];

$models = new Models(new ProviderRegistry([
    OpenAICompatiblePreset::deepSeek()->provider(),
]));
$modelGateway = new ModelsGateway(
    models: $models,
    resolver: new ModelsModelResolver($models),
    resultStore: new CycleModelCompletionResultStore($ormScope),
);

$toolCatalog = static function () use (
    $temporalConfig,
    $modelGateway,
    $ormScope,
    $telegramClient,
    $telegramSerializer,
): BotToolCatalog {
    $orm = $ormScope->current()->orm;

    return new BotToolCatalog(
        memoryStore: new ParticipantMemoryStore($orm),
        searchMessages: new SearchMessagesExecutor($orm),
        internetSearch: new InternetSearchExecutor(
            baseUrl: $temporalConfig->searchBaseUrl,
            timeoutSeconds: $temporalConfig->searchTimeoutSeconds,
        ),
        currentTime: new GetCurrentTimeExecutor(),
        telegramSchema: new TelegramApiSchemaExecutor(),
        telegramCall: new TelegramApiCallExecutor(
            client: $telegramClient,
            serializer: $telegramSerializer,
        ),
        listRuntimeCapabilities: new ListRuntimeCapabilitiesExecutor($orm),
        upsertRuntimeSkill: new UpsertRuntimeSkillExecutor($orm),
        upsertRuntimeTool: new UpsertRuntimeToolExecutor($orm),
        setRuntimeCapabilityStatus: new SetRuntimeCapabilityStatusExecutor($orm),
        runtimeTool: new RuntimeToolExecutor($orm, $modelGateway),
    );
};

return [
    'default' => [
        'workflows' => [
            AgenticWorkflow::class,
            DurableAgentWorkflow::class,
        ],
        'activities' => [
            DurableAgentActivities::class => fn(): DurableAgentActivities => new DurableAgentActivities(
                models: $modelGateway,
                tools: new RuntimeCapabilityAuthorizationGateway(
                    inner: new IdempotentToolExecutionGateway(
                        inner: new ToolRegistryGateway($toolCatalog()->registry()),
                        orm: $ormScope->current()->orm,
                    ),
                    authorization: $telegramAuthorization,
                ),
            ),
            AgentContextActivity::class => fn(): AgentContextActivity => new AgentContextActivity(
                new RuntimeCapabilityRegistry($ormScope->current()->orm),
            ),
            TelegramActivity::class => function () use ($ormScope): TelegramActivity {
                $context = $ormScope->current();

                return new TelegramActivity($context->orm, $context->entityManager);
            },
        ],
        'activityFinalizer' => static fn() => $ormScope->finalizeCurrent(),
    ],
];
