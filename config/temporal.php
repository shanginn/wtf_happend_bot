<?php

declare(strict_types=1);

use Bot\Llm\Tools\Chat\CreatePoll;
use Bot\Llm\Tools\Chat\GetCurrentTime;
use Bot\Llm\Tools\Chat\SearchMessages;
use Bot\Llm\Tools\Decision\RespondDecision;
use Bot\Llm\Tools\Image\DownloadImage;
use Bot\Llm\Tools\Memory\RecallMemory;
use Bot\Llm\Tools\Memory\SaveMemory;
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

if (!class_exists('Config')) {
    class Config
    {
        public function __construct(
            public readonly string $botToken,
            public readonly string $openrouterApiKey,
            public readonly string $deepseekApiKey,
            public readonly string $temporalCliAddress,
            public readonly string $temporalNamespace,
            public readonly ClientOptions $temporalClientOptions,
            public readonly DataConverter $dataConverter,
        ) {}
    }
}

$openaiDataConverter = new OpenaiDataConverter();
$openaiDataConverter->registerTools(
    RespondDecision::class,
    DownloadImage::class,
    SaveMemory::class,
    RecallMemory::class,
    SearchMessages::class,
    GetCurrentTime::class,
    CreatePoll::class,
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
    temporalClientOptions: (new ClientOptions())->withNamespace($temporalNamespace),
    dataConverter: $dataConverter,
);
