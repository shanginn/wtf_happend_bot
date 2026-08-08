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
        public ClientOptions $temporalClientOptions,
        public DataConverter $dataConverter,
    ) {}
}
