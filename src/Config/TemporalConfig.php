<?php

declare(strict_types=1);

namespace Bot\Config;

use Temporal\Client\ClientOptions;
use Temporal\DataConverter\DataConverter;

final readonly class TemporalConfig
{
    public function __construct(
        public string $botToken,
        public string $temporalAddress,
        public string $temporalNamespace,
        public string $searchBaseUrl,
        public int $searchTimeoutSeconds,
        public string $botInstanceId,
        public string $hostReleaseId,
        public bool $releaseIngressGate,
        public string $agentTaskQueue,
        public string $dreamTaskQueue,
        public string $dreamTimeZone,
        public int $dreamHour,
        public int $dreamMinute,
        public int $dreamJitterMinutes,
        public ClientOptions $temporalClientOptions,
        public DataConverter $dataConverter,
    ) {}
}
