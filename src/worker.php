<?php

declare(strict_types=1);

use Temporal\WorkerFactory;

ini_set('display_errors', 'stderr');
require_once __DIR__ . '/../vendor/autoload.php';

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
