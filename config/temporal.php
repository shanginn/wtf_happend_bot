<?php

declare(strict_types=1);

use Bot\Config\TemporalConfig;
use Bot\Config\TemporalExecutionIdentity;
use Bot\Space\Workflow\SpaceAgentWorkflowInputDataConverter;
use Bot\Telegram\Factory as TelegramFactory;
use Bot\Temporal\TelegramDataConverter;
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

$temporalAddress      = getenv('TEMPORAL_ADDRESS') ?: getenv('TEMPORAL_CLI_ADDRESS') ?: 'localhost:7233';
$temporalNamespace    = getenv('TEMPORAL_NAMESPACE') ?: 'default';
$searchBaseUrl        = getenv('SEARCH_BASE_URL') ?: 'http://searxng:8080';
$searchTimeoutSeconds = (int) (getenv('SEARCH_TIMEOUT_SECONDS') ?: 10);
$searchTimeoutSeconds = max(1, min($searchTimeoutSeconds, 30));
$botInstanceId        = trim((string) (getenv('BOT_INSTANCE_ID') ?: 'default'));
$hostReleaseId        = trim((string) (getenv('HOST_RELEASE_ID') ?: 'local'));
$releaseIngressGate   = filter_var(
    getenv('RELEASE_INGRESS_GATE') ?: 'false',
    FILTER_VALIDATE_BOOL,
);
$executionIdentity    = new TemporalExecutionIdentity(
    hostReleaseId: $hostReleaseId,
    agentTaskQueue: trim((string) (getenv('SPACE_AGENT_TASK_QUEUE') ?: 'space-agent-v1')),
    dreamTaskQueue: trim((string) (getenv('SPACE_DREAM_TASK_QUEUE') ?: 'space-dream-v1')),
);
$dreamTimeZone        = trim((string) (getenv('SPACE_DREAM_TIME_ZONE') ?: 'Asia/Yekaterinburg'));
$dreamHour            = max(0, min(23, (int) (getenv('SPACE_DREAM_HOUR') ?: 3)));
$dreamMinute          = max(0, min(59, (int) (getenv('SPACE_DREAM_MINUTE') ?: 17)));
$dreamJitterMinutes   = max(0, min(180, (int) (getenv('SPACE_DREAM_JITTER_MINUTES') ?: 30)));

$dataConverter = new DataConverter(
    new SpaceAgentWorkflowInputDataConverter(),
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
    botInstanceId: $botInstanceId,
    hostReleaseId: $hostReleaseId,
    releaseIngressGate: $releaseIngressGate,
    agentTaskQueue: $executionIdentity->agentTaskQueue,
    dreamTaskQueue: $executionIdentity->dreamTaskQueue,
    dreamTimeZone: $dreamTimeZone,
    dreamHour: $dreamHour,
    dreamMinute: $dreamMinute,
    dreamJitterMinutes: $dreamJitterMinutes,
    temporalClientOptions: (new ClientOptions())->withNamespace($temporalNamespace),
    dataConverter: $dataConverter,
);
