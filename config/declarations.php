<?php

declare(strict_types=1);

/** @var Config $config */

use Bot\Activity\DatabaseActivity;
use Bot\Activity\LlmActivity;
use Bot\Activity\TelegramActivity;
use Bot\RouterWorkflow\RouterActivity;
use Bot\RouterWorkflow\RouterWorkflow;
use Phenogram\Bindings\Api;
use Phenogram\Bindings\Serializer;
use Phenogram\Framework\TelegramBotApiClient;
use Shanginn\Openai\Openai;
use Shanginn\Openai\Openai\OpenaiClient;
use Shanginn\Openai\OpenaiSimple;

$config = require __DIR__ . '/temporal.php';

$deepseekClient = new OpenaiClient(
    apiKey: $config->deepseekApiKey,
    apiUrl: 'https://api.deepseek.com'
);

$deepseek = new Openai($deepseekClient, 'deepseek-chat');
$deepseekSimple = new OpenaiSimple($deepseek);

$telegramApi = new Api(
    client: new TelegramBotApiClient($config->botToken),
    serializer: new Serializer(),
);

return [
    'default' => [
        'workflows' => [
            RouterWorkflow::class,
        ],
        'activities' => [
            RouterActivity::class => fn () => new RouterActivity(
                openai: $deepseek,
                telegramSerializer: new Serializer()
            ),
            LlmActivity::class => fn () => new LlmActivity(
                openaiSimple: $deepseekSimple,
                openai: $deepseek,
            ),
            TelegramActivity::class => fn () => new TelegramActivity($telegramApi),
        ],
    ],
];
