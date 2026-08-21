<?php

declare(strict_types=1);

use Bot\Config\TemporalConfig;
use Bot\Entity\Space;
use Bot\Space\Dream\DreamScheduleManager;
use Bot\Space\Operations\HostReleaseReconciler;
use Bot\Space\Operations\HostReleaseStateStore;
use Bot\Space\Operations\LegacyCommandMigration;
use Bot\Space\Operations\LegacyWorkflowTerminator;
use Bot\Space\Operations\ReleaseIngressPreflight;
use Bot\Space\Operations\ReleaseWorkerPreflight;
use Bot\Telegram\TelegramBindingsSerializer;
use Cycle\ORM\ORMInterface;
use Phenogram\Bindings\Api;
use Phenogram\Framework\TelegramBotApiClient;
use Temporal\Client\GRPC\ServiceClient;
use Temporal\Client\ScheduleClient;
use Temporal\Client\WorkflowClient;

require_once __DIR__ . '/../vendor/autoload.php';

Dotenv\Dotenv::createUnsafeImmutable(__DIR__ . '/..')->safeLoad();

/** @var TemporalConfig $config */
$config  = require __DIR__ . '/../config/temporal.php';
$service = ServiceClient::create($config->temporalAddress);
$command = $argv[1] ?? 'help';

$releaseArgument = static function (array $arguments) use ($config): string {
    $releaseId = null;
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, '--release-id=')) {
            $releaseId = substr($argument, strlen('--release-id='));
        }
    }
    if (!is_string($releaseId) || $releaseId === '') {
        throw new InvalidArgumentException('Operation requires --release-id=<immutable image identity>.');
    }
    if (!hash_equals($config->hostReleaseId, $releaseId)) {
        throw new InvalidArgumentException('Release argument does not match HOST_RELEASE_ID.');
    }

    return $releaseId;
};

if ($command === 'migrate-legacy-commands') {
    $releaseId = $releaseArgument($argv);
    /** @var ORMInterface $orm */
    [, $orm]   = require __DIR__ . '/../config/orm.php';
    $database  = $orm->getSource(Space::class)->getDatabase();
    $migration = new LegacyCommandMigration($database, $config->botInstanceId);
    $apply     = in_array('--apply', $argv, true);
    $report    = $apply ? $migration->apply($releaseId) : $migration->preview();
    echo json_encode(
        $report,
        \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES,
    ), "\n";

    exit(0);
}

if ($command === 'preflight-workers') {
    $releaseId = $releaseArgument($argv);

    /** @var ORMInterface $orm */
    [, $orm]  = require __DIR__ . '/../config/orm.php';
    $database = $orm->getSource(Space::class)->getDatabase();

    $client = new WorkflowClient(
        serviceClient: $service,
        options: $config->temporalClientOptions,
        converter: $config->dataConverter,
    );
    $report = (new ReleaseWorkerPreflight(
        $client,
        $database,
        $config->botInstanceId,
    ))->run(
        releaseId: $releaseId,
        attemptId: bin2hex(random_bytes(12)),
        agentTaskQueue: $config->agentTaskQueue,
        dreamTaskQueue: $config->dreamTaskQueue,
    );

    echo json_encode([
        'releaseId' => $releaseId,
        'workers'   => $report,
    ], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

if ($command === 'preflight-ingress') {
    /** @var ORMInterface $orm */
    [, $orm]  = require __DIR__ . '/../config/orm.php';
    $database = $orm->getSource(Space::class)->getDatabase();
    $telegram = new Api(
        client: new TelegramBotApiClient($config->botToken),
        serializer: new TelegramBindingsSerializer(),
    );
    $report = (new ReleaseIngressPreflight($database, $telegram))->run();

    echo json_encode([
        'ingress' => $report,
    ], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

if (in_array($command, [
    'prepare-release',
    'abort-release',
    'authorize-release',
    'release-status',
    'confirm-ingress-retired',
    'reconcile-release',
], true)) {
    $releaseId = $releaseArgument($argv);
    /** @var ORMInterface $orm */
    [, $orm] = require __DIR__ . '/../config/orm.php';
    $states  = new HostReleaseStateStore($orm->getSource(Space::class)->getDatabase());

    if (in_array($command, [
        'prepare-release',
        'abort-release',
        'authorize-release',
        'release-status',
        'confirm-ingress-retired',
    ], true)) {
        $status = match ($command) {
            'prepare-release'         => $states->prepare($releaseId),
            'abort-release'           => $states->abortPrepared($releaseId),
            'authorize-release'       => $states->authorize($releaseId),
            'confirm-ingress-retired' => $states->confirmIngressRetired($releaseId),
            default                   => $states->status($releaseId) ?? 'missing',
        };
        echo json_encode([
            'releaseId' => $releaseId,
            'status'    => $status,
        ], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES), "\n";
        if ($command === 'abort-release' && !in_array($status, ['aborted', 'missing'], true)) {
            exit(4);
        }
        // A caller may parse every valid phase, but CI success means the bot
        // gate has really opened, not merely that cutover was authorized.
        exit($command === 'release-status' && $status !== 'active' ? 3 : 0);
    }

    $workflowClient = new WorkflowClient(
        serviceClient: $service,
        options: $config->temporalClientOptions,
        converter: $config->dataConverter,
    );
    $scheduleClient = ScheduleClient::create(
        serviceClient: $service,
        options: $config->temporalClientOptions,
        converter: $config->dataConverter,
    );
    $report = (new HostReleaseReconciler(
        states: $states,
        schedules: new DreamScheduleManager($scheduleClient),
        workflows: new LegacyWorkflowTerminator($workflowClient, $releaseId),
    ))->reconcile(
        releaseId: $releaseId,
        dreamTaskQueue: $config->dreamTaskQueue,
        dreamEnabled: $config->dreamEnabled,
        dreamTimeZone: $config->dreamTimeZone,
        dreamHour: $config->dreamHour,
        dreamMinute: $config->dreamMinute,
        dreamJitterMinutes: $config->dreamJitterMinutes,
    );

    echo json_encode($report, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES), "\n";

    exit(0);
}

fwrite(STDERR, <<<'USAGE'
    Usage:
      php src/space-v2-admin.php preflight-workers --release-id=<image revision>
      php src/space-v2-admin.php preflight-ingress
      php src/space-v2-admin.php prepare-release --release-id=<immutable image identity>
      php src/space-v2-admin.php abort-release --release-id=<immutable image identity>
      php src/space-v2-admin.php authorize-release --release-id=<immutable image identity>
      php src/space-v2-admin.php release-status --release-id=<immutable image identity>
      php src/space-v2-admin.php confirm-ingress-retired --release-id=<immutable image identity>
      php src/space-v2-admin.php reconcile-release --release-id=<immutable image identity>
      php src/space-v2-admin.php migrate-legacy-commands --release-id=<active image identity> [--apply]

    USAGE);
exit(64);
