<?php

declare(strict_types=1);

/** @var Config $config */

use Bot\Openai\CompatibleOpenai;
use Bot\Activity\DatabaseActivity;
use Bot\Activity\ImageSkillActivity;
use Bot\Activity\LlmActivity;
use Bot\Activity\TelegramActivity;
use Bot\AgenticWorkflow\AgenticActivity;
use Bot\AgenticWorkflow\AgenticWorkflow;
use Bot\Entity\Message;
use Bot\Infrastructure\CycleORM\CycleOrmScope;
use Bot\Llm\Tools\Chat\GetCurrentTimeExecutor;
use Bot\Llm\Tools\Chat\SearchMessagesExecutor;
use Bot\Llm\Tools\Memory\ForgetMemoryExecutor;
use Bot\Llm\Tools\Memory\RecallMemoryExecutor;
use Bot\Llm\Tools\Memory\SaveMemoryExecutor;
use Bot\Llm\Tools\Memory\UpdateMemoryExecutor;
use Bot\Llm\Tools\Runtime\ListRuntimeCapabilitiesExecutor;
use Bot\Llm\Tools\Runtime\RuntimeToolExecutor;
use Bot\Llm\Tools\Runtime\SetRuntimeCapabilityStatusExecutor;
use Bot\Llm\Tools\Runtime\UpsertRuntimeSkillExecutor;
use Bot\Llm\Tools\Runtime\UpsertRuntimeToolExecutor;
use Bot\Llm\Tools\Search\InternetSearchExecutor;
use Bot\Llm\Tools\Telegram\TelegramApiCallExecutor;
use Bot\Llm\Tools\Telegram\TelegramApiSchemaExecutor;
use Bot\Memory\ParticipantMemoryStore;
use Bot\RouterWorkflow\RouterActivity;
use Bot\RouterWorkflow\RouterWorkflow;
use Phenogram\Bindings\Api;
use Phenogram\Bindings\Serializer;
use Phenogram\Framework\TelegramBotApiClient;
use Shanginn\Openai\Openai\OpenaiClient;
use Shanginn\Openai\OpenaiSimple;

$config = require __DIR__ . '/temporal.php';

$deepseek = new CompatibleOpenai(new OpenaiClient(
    apiKey: $config->deepseekApiKey,
    apiUrl: 'https://api.deepseek.com'
), 'deepseek-v4-flash');

$deepseekFlash = new CompatibleOpenai(new OpenaiClient(
    apiKey: $config->deepseekApiKey,
    apiUrl: 'https://api.deepseek.com'
), 'deepseek-v4-flash');

$minimax = new CompatibleOpenai(new OpenaiClient(
    apiKey: $config->openrouterApiKey,
    apiUrl: 'https://openrouter.ai/api/v1'
), 'minimax/minimax-m2.7');

$qwen = new CompatibleOpenai(new OpenaiClient(
    apiKey: $config->openrouterApiKey,
    apiUrl: 'https://openrouter.ai/api/v1'
), 'qwen/qwen3.6-plus');

$bytedanceSeed = new CompatibleOpenai(new OpenaiClient(
    apiKey: $config->openrouterApiKey,
    apiUrl: 'https://openrouter.ai/api/v1'
), 'bytedance-seed/seed-2.0-mini');

$decisionModel = $deepseekFlash;
$memoryRecollectionModel = $deepseekFlash;
$answerGenerationModel = $deepseek;

$telegramClient = new TelegramBotApiClient($config->botToken);
$telegramSerializer = new Serializer();
$telegramApi = new Api(
    client: $telegramClient,
    serializer: $telegramSerializer,
);

$ormData = require __DIR__ . '/orm.php';
/** @var CycleOrmScope $ormScope */
$ormScope = $ormData[2];

return [
    'default' => [
        'workflows' => [
            RouterWorkflow::class,
            AgenticWorkflow::class,
        ],
        'activities' => [
            RouterActivity::class => fn () => new RouterActivity(
                openai: $answerGenerationModel,
                telegramSerializer: new Serializer(),
            ),
            AgenticActivity::class => function () use (
                $answerGenerationModel,
                $telegramApi,
                $ormScope,
                $decisionModel,
                $memoryRecollectionModel,
            ): AgenticActivity {
                return new AgenticActivity(
                    openai: $answerGenerationModel,
                    api: $telegramApi,
                    orm: $ormScope->current()->orm,
                    decisionOpenai: $decisionModel,
                    memoryRecollectionOpenai: $memoryRecollectionModel,
                );
            },
            LlmActivity::class => fn () => new LlmActivity(
                openai: $answerGenerationModel,
            ),
            TelegramActivity::class => function () use ($telegramApi, $ormScope): TelegramActivity {
                $context = $ormScope->current();

                return new TelegramActivity($telegramApi, $context->orm, $context->entityManager);
            },
            DatabaseActivity::class => fn () => new DatabaseActivity($ormScope->current()->orm),
            SaveMemoryExecutor::class => fn () => new SaveMemoryExecutor(
                memoryStore: new ParticipantMemoryStore($ormScope->current()->orm),
                api: $telegramApi,
            ),
            RecallMemoryExecutor::class => fn () => new RecallMemoryExecutor(
                memoryStore: new ParticipantMemoryStore($ormScope->current()->orm),
            ),
            UpdateMemoryExecutor::class => fn () => new UpdateMemoryExecutor(
                memoryStore: new ParticipantMemoryStore($ormScope->current()->orm),
                api: $telegramApi,
            ),
            ForgetMemoryExecutor::class => fn () => new ForgetMemoryExecutor(
                memoryStore: new ParticipantMemoryStore($ormScope->current()->orm),
                api: $telegramApi,
            ),
            SearchMessagesExecutor::class => fn () => new SearchMessagesExecutor(
                orm: $ormScope->current()->orm,
            ),
            InternetSearchExecutor::class => fn () => new InternetSearchExecutor(
                baseUrl: $config->searchBaseUrl ?? null,
                timeoutSeconds: $config->searchTimeoutSeconds ?? null,
            ),
            GetCurrentTimeExecutor::class => fn () => new GetCurrentTimeExecutor(),
            TelegramApiSchemaExecutor::class => fn () => new TelegramApiSchemaExecutor(),
            TelegramApiCallExecutor::class => fn () => new TelegramApiCallExecutor(
                client: $telegramClient,
                serializer: $telegramSerializer,
            ),
            ListRuntimeCapabilitiesExecutor::class => fn () => new ListRuntimeCapabilitiesExecutor(
                orm: $ormScope->current()->orm,
            ),
            UpsertRuntimeSkillExecutor::class => fn () => new UpsertRuntimeSkillExecutor(
                orm: $ormScope->current()->orm,
                api: $telegramApi,
            ),
            UpsertRuntimeToolExecutor::class => fn () => new UpsertRuntimeToolExecutor(
                orm: $ormScope->current()->orm,
                api: $telegramApi,
            ),
            SetRuntimeCapabilityStatusExecutor::class => fn () => new SetRuntimeCapabilityStatusExecutor(
                orm: $ormScope->current()->orm,
            ),
            RuntimeToolExecutor::class => fn () => new RuntimeToolExecutor(
                orm: $ormScope->current()->orm,
                openai: $answerGenerationModel,
            ),
            ImageSkillActivity::class => fn () => new ImageSkillActivity($telegramApi),
        ],
        'activityFinalizer' => static fn () => $ormScope->finalizeCurrent(),
    ],
];
