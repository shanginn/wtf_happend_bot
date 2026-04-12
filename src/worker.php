<?php

declare(strict_types=1);

use Spiral\RoadRunner\Environment;
use Temporal\WorkerFactory;

ini_set('display_errors', 'stderr');
require_once __DIR__ . '/../vendor/autoload.php';

$rrEnvironment = Environment::fromGlobals();
$hasRoadRunnerRelay =
    isset($_SERVER['RR_RELAY'], $_SERVER['RR_RPC'])
    || isset($_ENV['RR_RELAY'], $_ENV['RR_RPC'])
    || isset($_SERVER['RR_MODE'], $_SERVER['RR_VERSION'])
    || isset($_ENV['RR_MODE'], $_ENV['RR_VERSION']);

if (!$hasRoadRunnerRelay) {
    fwrite(STDERR, "Temporal worker must be started via RoadRunner. Use `rr serve -c .rr.yaml`.\n");
    exit(1);
}

/** @var Config $config */
$config = require __DIR__ . '/../config/temporal.php';

$factory = WorkerFactory::create(
    converter: $config->dataConverter
);

$worker = $factory->newWorker();

$declarationPath = realpath(__DIR__ . '/../config/declarations.php');

$declarations = is_file($declarationPath) ? include $declarationPath : [];

foreach ($declarations as $package => $declaration) {
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
}

$factory->run();
