<?php

declare(strict_types=1);

use Bot\AgenticWorkflow\AgenticToolset;
use Bot\Llm\Tools\Image\DownloadImage;
use Bot\Temporal\OpenaiDataConverter;
use Bot\Temporal\TelegramDataConverter;
use Bot\Telegram\Factory as TelegramFactory;
use Phenogram\Bindings\Factory;
use Temporal\Client\ClientOptions;
use Temporal\DataConverter\BinaryConverter;
use Temporal\DataConverter\DataConverter;
use Temporal\DataConverter\JsonConverter;
use Temporal\DataConverter\NullConverter;
use Temporal\DataConverter\ProtoConverter;
use Temporal\DataConverter\ProtoJsonConverter;

require_once __DIR__ . '/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

$botToken = getenv('TELEGRAM_BOT_TOKEN');
assert(is_string($botToken), 'Bot token must be a string');

$openrouterApiKey = getenv('OPENROUTER_API_KEY');
assert(is_string($openrouterApiKey), 'OpenRouter API key must be a string');

$deepseekApiKey = getenv('DEEPSEEK_API_KEY');
assert(is_string($deepseekApiKey), 'DeepSeek API key must be a string');

$temporalCliAddress = getenv('TEMPORAL_CLI_ADDRESS') ?: 'localhost:7233';
$temporalNamespace = getenv('TEMPORAL_NAMESPACE') ?: 'default';
$searchBaseUrl = getenv('SEARCH_BASE_URL') ?: 'http://searxng:8080';
$searchTimeoutSeconds = (int) (getenv('SEARCH_TIMEOUT_SECONDS') ?: 10);
$searchTimeoutSeconds = max(1, min($searchTimeoutSeconds, 30));

if (!class_exists('Config')) {
    class Config
    {
        public function __construct(
            public readonly string $botToken,
            public readonly string $openrouterApiKey,
            public readonly string $deepseekApiKey,
            public readonly string $temporalCliAddress,
            public readonly string $temporalNamespace,
            public readonly string $searchBaseUrl,
            public readonly int $searchTimeoutSeconds,
            public readonly ClientOptions $temporalClientOptions,
            public readonly DataConverter $dataConverter,
        ) {}
    }
}

$openaiDataConverter = new OpenaiDataConverter();
$openaiDataConverter->registerTools(
    DownloadImage::class,
    ...AgenticToolset::TOOLS,
);

$dataConverter = new DataConverter(
    $openaiDataConverter,
    new TelegramDataConverter(factory: new TelegramFactory()),
    new NullConverter(),
    new BinaryConverter(),
    new ProtoJsonConverter(),
    new ProtoConverter(),
    new JsonConverter(),
);

return new Config(
    botToken: $botToken,
    openrouterApiKey: $openrouterApiKey,
    deepseekApiKey: $deepseekApiKey,
    temporalCliAddress: $temporalCliAddress,
    temporalNamespace: $temporalNamespace,
    searchBaseUrl: $searchBaseUrl,
    searchTimeoutSeconds: $searchTimeoutSeconds,
    temporalClientOptions: (new ClientOptions())->withNamespace($temporalNamespace),
    dataConverter: $dataConverter,
);
