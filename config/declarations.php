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
use Bot\Llm\Tools\Chat\GetCurrentTimeExecutor;
use Bot\Llm\Tools\Chat\SearchMessagesExecutor;
use Bot\Llm\Tools\Memory\ForgetMemoryExecutor;
use Bot\Llm\Tools\Memory\RecallMemoryExecutor;
use Bot\Llm\Tools\Memory\SaveMemoryExecutor;
use Bot\Llm\Tools\Memory\UpdateMemoryExecutor;
use Bot\Llm\Tools\Telegram\TelegramApiCallExecutor;
use Bot\Llm\Tools\Telegram\TelegramApiSchemaExecutor;
use Bot\Memory\ParticipantMemoryStore;
use Bot\RouterWorkflow\RouterActivity;
use Bot\RouterWorkflow\RouterWorkflow;
use Cycle\ORM\EntityManager;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\ORMInterface;
use Phenogram\Bindings\Api;
use Phenogram\Bindings\Serializer;
use Phenogram\Framework\TelegramBotApiClient;
use Shanginn\Openai\Openai\OpenaiClient;
use Shanginn\Openai\OpenaiSimple;
use Spiral\Core\Container;

$config = require __DIR__ . '/temporal.php';

$deepseek = new CompatibleOpenai(new OpenaiClient(
    apiKey: $config->deepseekApiKey,
    apiUrl: 'https://api.deepseek.com'
), 'deepseek-v4-pro');

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
$orm = $ormData[1];
$container = $ormData[0];

$em = new EntityManager($orm);
$container->bind(EntityManagerInterface::class, $em);
$participantMemoryStore = new ParticipantMemoryStore($orm);

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
            AgenticActivity::class => fn () => new AgenticActivity(
                openai: $answerGenerationModel,
                api: $telegramApi,
                orm: $orm,
                decisionOpenai: $decisionModel,
                memoryRecollectionOpenai: $memoryRecollectionModel,
            ),
            LlmActivity::class => fn () => new LlmActivity(
                openai: $answerGenerationModel,
            ),
            TelegramActivity::class => fn () => new TelegramActivity($telegramApi, $orm, $em),
            DatabaseActivity::class => fn () => new DatabaseActivity($orm),
            SaveMemoryExecutor::class => fn () => new SaveMemoryExecutor(
                memoryStore: $participantMemoryStore,
                api: $telegramApi,
            ),
            RecallMemoryExecutor::class => fn () => new RecallMemoryExecutor(
                memoryStore: $participantMemoryStore,
            ),
            UpdateMemoryExecutor::class => fn () => new UpdateMemoryExecutor(
                memoryStore: $participantMemoryStore,
                api: $telegramApi,
            ),
            ForgetMemoryExecutor::class => fn () => new ForgetMemoryExecutor(
                memoryStore: $participantMemoryStore,
                api: $telegramApi,
            ),
            SearchMessagesExecutor::class => fn () => new SearchMessagesExecutor(
                orm: $orm,
            ),
            GetCurrentTimeExecutor::class => fn () => new GetCurrentTimeExecutor(),
            TelegramApiSchemaExecutor::class => fn () => new TelegramApiSchemaExecutor(),
            TelegramApiCallExecutor::class => fn () => new TelegramApiCallExecutor(
                client: $telegramClient,
                serializer: $telegramSerializer,
            ),
            ImageSkillActivity::class => fn () => new ImageSkillActivity($telegramApi),
        ],
    ],
];
