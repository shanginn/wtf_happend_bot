<?php

declare(strict_types=1);

/** @var Config $config */

use Bot\Activity\DatabaseActivity;
use Bot\Activity\ImageSkillActivity;
use Bot\Activity\LlmActivity;
use Bot\Activity\TelegramActivity;
use Bot\AgenticWorkflow\AgenticActivity;
use Bot\AgenticWorkflow\AgenticWorkflow;
use Bot\Entity\Message;
use Bot\RouterWorkflow\RouterActivity;
use Bot\RouterWorkflow\RouterWorkflow;
use Cycle\ORM\EntityManager;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\ORMInterface;
use Phenogram\Bindings\Api;
use Phenogram\Bindings\Serializer;
use Phenogram\Framework\TelegramBotApiClient;
use Shanginn\Openai\Openai;
use Shanginn\Openai\Openai\OpenaiClient;
use Shanginn\Openai\OpenaiSimple;
use Spiral\Core\Container;

$config = require __DIR__ . '/temporal.php';

$deepseek = new Openai(new OpenaiClient(
    apiKey: $config->deepseekApiKey,
    apiUrl: 'https://api.deepseek.com'
), 'deepseek-chat');

$minimax = new Openai(new OpenaiClient(
    apiKey: $config->openrouterApiKey,
    apiUrl: 'https://openrouter.ai/api/v1'
), 'minimax/minimax-m2.5');

$qwen35 = new Openai(new OpenaiClient(
    apiKey: $config->openrouterApiKey,
    apiUrl: 'https://openrouter.ai/api/v1'
), 'qwen/qwen3.5-plus-02-15');

$bytedanceSeed = new Openai(new OpenaiClient(
    apiKey: $config->openrouterApiKey,
    apiUrl: 'https://openrouter.ai/api/v1'
), 'bytedance-seed/seed-2.0-mini');

$telegramApi = new Api(
    client: new TelegramBotApiClient($config->botToken),
    serializer: new Serializer(),
);

$ormData = require __DIR__ . '/orm.php';
$orm = $ormData[1];
$container = $ormData[0];

$em = new EntityManager($orm);
$container->bind(EntityManagerInterface::class, $em);

return [
    'default' => [
        'workflows' => [
            RouterWorkflow::class,
            AgenticWorkflow::class,
        ],
        'activities' => [
            RouterActivity::class => fn () => new RouterActivity(
                openai: $qwen35,
                telegramSerializer: new Serializer(),
            ),
            AgenticActivity::class => fn () => new AgenticActivity(
                openai: $qwen35,
                api: $telegramApi,
            ),
            LlmActivity::class => fn () => new LlmActivity(
                openai: $qwen35,
            ),
            TelegramActivity::class => fn () => new TelegramActivity($telegramApi, $orm, $em),
            DatabaseActivity::class => fn () => new DatabaseActivity($orm),
            ImageSkillActivity::class => fn () => new ImageSkillActivity($telegramApi),
        ],
    ],
];
