<?php

declare(strict_types=1);

use Bot\Activity\TelegramActivity;
use Bot\AgenticWorkflow\BotToolCatalog;
use Bot\AgenticWorkflow\CycleModelCompletionResultStore;
use Bot\AgenticWorkflow\IdempotentToolExecutionGateway;
use Bot\AgenticWorkflow\RuntimeCapabilityAuthorizationGateway;
use Bot\Config\TemporalConfig;
use Bot\Entity\Space;
use Bot\Infrastructure\CycleORM\CycleOrmScope;
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
use Bot\Space\Dream\DreamActivities;
use Bot\Space\Dream\DreamCoordinatorWorkflow;
use Bot\Space\Dream\SpaceDreamWorkflow;
use Bot\Space\Operations\AgentWorkerHealthWorkflow;
use Bot\Space\Operations\DreamWorkerHealthWorkflow;
use Bot\Space\Persistence\SpaceMemoryStore;
use Bot\Space\Persistence\SpaceStore;
use Bot\Space\Runtime\SpaceRuntimeSnapshotLoaderActivity;
use Bot\Space\Runtime\SpaceRuntimeSnapshotLoaderActivityInterface;
use Bot\Space\Tools\SpaceMemoryToolStore;
use Bot\Space\Tools\SpaceToolCatalog;
use Bot\Space\Workflow\SpaceAgentWorkflow;
use Bot\Telegram\TelegramBindingsSerializer;
use Bot\Telegram\TelegramChatAuthorizationPolicy;
use Cycle\Database\DatabaseInterface;
use Cycle\ORM\ORMInterface;
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

$telegramClient        = new TelegramBotApiClient($temporalConfig->botToken);
$telegramSerializer    = new TelegramBindingsSerializer();
$telegramApi           = new Api(client: $telegramClient, serializer: $telegramSerializer);
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

$database = static function (ORMInterface $orm): DatabaseInterface {
    return $orm->getSource(Space::class)->getDatabase();
};
$spaceStore = static function () use ($database, $ormScope): SpaceStore {
    $orm = $ormScope->current()->orm;

    return new SpaceStore($orm, $database($orm));
};

$toolCatalog = static function () use (
    $database,
    $modelGateway,
    $ormScope,
    $spaceStore,
    $telegramAuthorization,
    $telegramClient,
    $telegramSerializer,
    $temporalConfig,
): BotToolCatalog {
    $orm           = $ormScope->current()->orm;
    $spaceDatabase = $database($orm);
    $spaces        = $spaceStore();

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
        // These legacy executors stay registered only so old PHP unit fixtures
        // can construct the catalog. Space snapshots do not expose their tools.
        listRuntimeCapabilities: new ListRuntimeCapabilitiesExecutor($orm),
        upsertRuntimeSkill: new UpsertRuntimeSkillExecutor($orm),
        upsertRuntimeTool: new UpsertRuntimeToolExecutor($orm),
        setRuntimeCapabilityStatus: new SetRuntimeCapabilityStatusExecutor($orm),
        runtimeTool: new RuntimeToolExecutor($orm, $modelGateway),
        spaceMemoryStore: new SpaceMemoryToolStore(
            new SpaceMemoryStore($spaces, $spaceDatabase),
        ),
        spaceCapsules: null,
        spaceMemoryAuthorization: $telegramAuthorization,
    );
};

$activityFinalizer = static fn () => $ormScope->finalizeCurrent();

return [
    'space-agent-v1' => [
        'taskQueue' => $temporalConfig->agentTaskQueue,
        'workflows' => [
            AgentWorkerHealthWorkflow::class,
            SpaceAgentWorkflow::class,
            DurableAgentWorkflow::class,
        ],
        'activities' => [
            DurableAgentActivities::class => fn (): DurableAgentActivities => new DurableAgentActivities(
                models: $modelGateway,
                tools: new RuntimeCapabilityAuthorizationGateway(
                    inner: new IdempotentToolExecutionGateway(
                        inner: new ToolRegistryGateway($toolCatalog()->registry(
                            SpaceToolCatalog::toolNames(),
                        )),
                        orm: $ormScope->current()->orm,
                    ),
                    authorization: $telegramAuthorization,
                ),
            ),
            SpaceRuntimeSnapshotLoaderActivityInterface::class => function () use (
                $database,
                $ormScope,
            ): SpaceRuntimeSnapshotLoaderActivity {
                $orm = $ormScope->current()->orm;

                return new SpaceRuntimeSnapshotLoaderActivity(
                    database: $database($orm),
                    tools: SpaceToolCatalog::wireDefinitions(),
                );
            },
            TelegramActivity::class => function () use ($ormScope): TelegramActivity {
                $context = $ormScope->current();

                return new TelegramActivity($context->orm, $context->entityManager);
            },
        ],
        'activityFinalizer' => $activityFinalizer,
    ],
    'space-dream-v1' => [
        'taskQueue' => $temporalConfig->dreamTaskQueue,
        'workflows' => [
            DreamWorkerHealthWorkflow::class,
            DreamCoordinatorWorkflow::class,
            SpaceDreamWorkflow::class,
        ],
        'activities' => [
            DreamActivities::class => function () use (
                $database,
                $modelGateway,
                $ormScope,
                $spaceStore,
            ): DreamActivities {
                $orm = $ormScope->current()->orm;

                return new DreamActivities(
                    database: $database($orm),
                    spaces: $spaceStore(),
                    models: $modelGateway,
                );
            },
        ],
        'activityFinalizer' => $activityFinalizer,
    ],
];
