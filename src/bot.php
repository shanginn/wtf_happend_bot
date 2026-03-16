<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Bot\Entity;
use Bot\Handler\SaveUpdateHandler;
use Bot\AgenticWorkflow\AgenticWorkflowHandler;
use Bot\Telegram\Factory;
use Bot\Telegram\Update;
use Cycle\ORM\EntityManager;
use Phenogram\Bindings\Types\Interfaces\UpdateInterface;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\ORMInterface;
use Phenogram\Bindings\Serializer;
use Phenogram\Framework\TelegramBot;
use Phenogram\Framework\TelegramBotApiClient;
use Spiral\Core\Container;
use Temporal\Client\GRPC\ServiceClient;
use Temporal\Client\WorkflowClient;

Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

[
    'botToken' => $botToken,
] = require __DIR__ . '/../config/config.php';

/** @var Config $temporalConfig */
$temporalConfig = require __DIR__ . '/../config/temporal.php';

$bot = new TelegramBot(
    $botToken,
    api: new \Bot\Bot\ExtendedApi(
        client: new TelegramBotApiClient($botToken),
        serializer: new Serializer(new Factory()),
    )
);

/** @var ORMInterface $orm */
/** @var Container $container */
[$container, $orm] = require __DIR__ . '/../config/orm.php';
$em = new EntityManager($orm);

$container->bind(EntityManagerInterface::class, $em);

$saveUpdateHandler = new SaveUpdateHandler($em);

$workflowClient = new WorkflowClient(
    serviceClient: ServiceClient::create($temporalConfig->temporalCliAddress),
    converter: $temporalConfig->dataConverter
);

$agenticWorkflowHandler = new AgenticWorkflowHandler(
    client: $workflowClient
);
//
//$bot
//    ->addHandler($saveUpdateHandler)
//    ->supports($saveUpdateHandler::supports(...));

$bot
    ->addHandler(function (UpdateInterface $update, TelegramBot $bot) use ($agenticWorkflowHandler) {
        assert($update instanceof Update);
        $agenticWorkflowHandler->handleUpdate($update);
    });

$pressedCtrlC = false;
$gracefulShutdown = function (int $signal) use ($bot, &$pressedCtrlC, $em): void {
    if ($pressedCtrlC) {
        echo "Shutting down now...\n";
        exit(0);
    }

    $keysCombination = $signal === SIGINT ? 'Ctrl+C' : 'Ctrl+Break';

    echo "\n{$keysCombination} pressed. Gracefully shutting down...\nPress it again to force shutdown.\n\n";

    $pressedCtrlC = true;

    try {
        $em->run();
    } catch (Throwable) {
    }

    try {
        $em->clean();
    } catch (Throwable) {
    }

    try {
        $bot->stop();
    } catch (Throwable) {
    }

    exit(0);
};

pcntl_signal(SIGTERM, $gracefulShutdown);
pcntl_signal(SIGINT, $gracefulShutdown);

$bot->run();
