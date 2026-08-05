<?php

declare(strict_types=1);

use Bot\Temporal\AgenticWorkflowInputDataConverter;
use Bot\Temporal\TelegramDataConverter;
use Bot\Telegram\Factory as TelegramFactory;
use Bot\Config\TemporalConfig;
use Phenogram\Bindings\Factory;
use Temporal\Client\ClientOptions;
use Temporal\DataConverter\BinaryConverter;
use Temporal\DataConverter\DataConverter;
use Temporal\DataConverter\JsonConverter;
use Temporal\DataConverter\NullConverter;
use Temporal\DataConverter\ProtoConverter;
use Temporal\DataConverter\ProtoJsonConverter;

require_once __DIR__ . '/../vendor/autoload.php';

Dotenv\Dotenv::createUnsafeImmutable(__DIR__ . '/..')->safeLoad();

$requiredEnvironment = static function (string $name): string {
    $value = getenv($name);
    if (!is_string($value) || trim($value) === '') {
        throw new InvalidArgumentException("{$name} is not set.");
    }

    return $value;
};

$botToken = $requiredEnvironment('TELEGRAM_BOT_TOKEN');
$requiredEnvironment('DEEPSEEK_API_KEY');

$temporalAddress = getenv('TEMPORAL_ADDRESS') ?: getenv('TEMPORAL_CLI_ADDRESS') ?: 'localhost:7233';
$temporalNamespace = getenv('TEMPORAL_NAMESPACE') ?: 'default';
$searchBaseUrl = getenv('SEARCH_BASE_URL') ?: 'http://searxng:8080';
$searchTimeoutSeconds = (int) (getenv('SEARCH_TIMEOUT_SECONDS') ?: 10);
$searchTimeoutSeconds = max(1, min($searchTimeoutSeconds, 30));

$dataConverter = new DataConverter(
    new AgenticWorkflowInputDataConverter(),
    new TelegramDataConverter(factory: new TelegramFactory()),
    new NullConverter(),
    new BinaryConverter(),
    new ProtoJsonConverter(),
    new ProtoConverter(),
    new JsonConverter(),
);

return new TemporalConfig(
    botToken: $botToken,
    temporalAddress: $temporalAddress,
    temporalNamespace: $temporalNamespace,
    searchBaseUrl: $searchBaseUrl,
    searchTimeoutSeconds: $searchTimeoutSeconds,
    temporalClientOptions: (new ClientOptions())->withNamespace($temporalNamespace),
    dataConverter: $dataConverter,
);
