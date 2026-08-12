<?php

declare(strict_types=1);

use Async\Signal;
use Bot\Config\TemporalConfig;
use Temporal\WorkerFactory;
use TrueAsync\Temporal\Core\Connection;

ini_set('display_errors', 'stderr');
require_once __DIR__ . '/../vendor/autoload.php';

/** @var TemporalConfig $config */
$config = require __DIR__ . '/../config/temporal.php';

$factory = WorkerFactory::create(
    converter: $config->dataConverter,
    connection: new Connection(address: $config->temporalAddress),
    namespace: $config->temporalNamespace,
);

$declarationPath = realpath(__DIR__ . '/../config/declarations.php');

$declarations  = is_file($declarationPath) ? include $declarationPath : [];
$packageFilter = trim((string) (getenv('WORKER_PACKAGE') ?: ''));
if ($packageFilter !== '') {
    if (!array_key_exists($packageFilter, $declarations)) {
        throw new InvalidArgumentException("Unknown WORKER_PACKAGE {$packageFilter}.");
    }
    $declarations = [$packageFilter => $declarations[$packageFilter]];
}

foreach ($declarations as $package => $declaration) {
    $taskQueue = $declaration['taskQueue'] ?? $package;
    if (!is_string($taskQueue) || trim($taskQueue) === '') {
        throw new InvalidArgumentException("Worker package {$package} has no task queue.");
    }
    $worker = $factory->newWorker($taskQueue);

    foreach ($declaration['workflows'] ?? [] as $workflow) {
        $worker->registerWorkflowTypes($workflow);
    }

    foreach ($declaration['activities'] ?? [] as $key => $value) {
        if ($value instanceof Closure) {
            $worker->registerActivity($key, $value);
        } else {
            $worker->registerActivity($value);
        }
    }

    if (($declaration['activityFinalizer'] ?? null) instanceof Closure) {
        $worker->registerActivityFinalizer($declaration['activityFinalizer']);
    }
}

$shutdownWatcher = \Async\spawn(static function () use ($factory): void {
    \Async\await_any_or_fail([
        \Async\signal(Signal::SIGINT),
        \Async\signal(Signal::SIGTERM),
    ]);

    $factory->shutdown();
});

try {
    $factory->run();
} finally {
    if (!$shutdownWatcher->isCompleted()) {
        $shutdownWatcher->cancel();
    }
}
