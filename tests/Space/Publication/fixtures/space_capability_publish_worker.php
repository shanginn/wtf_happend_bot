<?php

declare(strict_types=1);

use Bot\Infrastructure\CycleORM\TrueAsyncPostgresDriver;
use Bot\Space\Publication\SpaceCapabilityPublicationInput;
use Bot\Space\Publication\SpaceCapabilityPublisher;
use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\Config\Postgres\TcpConnectionConfig;
use Cycle\Database\Config\PostgresDriverConfig;
use Cycle\Database\DatabaseManager;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

$schema = $argv[1] ?? '';
if (preg_match('/\Aspace_publication_[a-f0-9]{16}\z/D', $schema) !== 1) {
    throw new RuntimeException('Invalid publication test schema.');
}
$encoded = fgets(STDIN);
if (!is_string($encoded)) {
    throw new RuntimeException('Missing publication worker input.');
}
$data = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
if (!is_array($data) || !is_array($data['request'] ?? null) || !is_array($data['provenance'] ?? null)) {
    throw new RuntimeException('Invalid publication worker input.');
}
fread(STDIN, 1);

$environment = static function (string $key, string $default): string {
    $value = getenv($key);

    return is_string($value) && $value !== '' ? $value : $default;
};
$manager = new DatabaseManager(new DatabaseConfig([
    'default'   => 'default',
    'databases' => [
        'default' => ['connection' => 'postgres'],
    ],
    'connections' => [
        'postgres' => new PostgresDriverConfig(
            connection: new TcpConnectionConfig(
                database: $environment('SPACE_PUBLICATION_PG_DATABASE', 'space_test'),
                host: $environment('SPACE_PUBLICATION_PG_HOST', '127.0.0.1'),
                port: (int) $environment('SPACE_PUBLICATION_PG_PORT', '5432'),
                user: $environment('SPACE_PUBLICATION_PG_USER', 'space_test'),
                password: $environment('SPACE_PUBLICATION_PG_PASSWORD', 'space_test'),
            ),
            driver: TrueAsyncPostgresDriver::class,
            reconnect: true,
            queryCache: false,
        ),
    ],
]));

try {
    $database = $manager->database();
    $database->execute('SET search_path TO "' . $schema . '"');
    $request = $data['request'];
    $result  = (new SpaceCapabilityPublisher($database))->publish(
        new SpaceCapabilityPublicationInput(
            spaceId: (string) $request['spaceId'],
            runtimeSnapshotId: (string) $request['runtimeSnapshotId'],
            terminalScopeId: (string) $request['terminalScopeId'],
            invocationKey: (string) $request['invocationKey'],
            kind: (string) $request['kind'],
            name: (string) $request['name'],
            description: (string) $request['description'],
            instructions: (string) $request['instructions'],
            authorizationProvenance: $data['provenance'],
            parametersSchema: $request['parametersSchema'],
            enabled: (bool) $request['enabled'],
        ),
        (int) ($data['now'] ?? time()),
    );
    $payload = [
        'status'    => 'ok',
        'releaseId' => $result->releaseId,
        'replayed'  => $result->replayed,
    ];
} catch (Throwable $error) {
    $payload = [
        'status'  => 'error',
        'message' => $error->getMessage(),
    ];
}

echo json_encode(
    $payload,
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
);
