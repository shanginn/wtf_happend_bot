<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Bot\AgenticWorkflow\AgenticWorkflowHandler;
use Bot\Bot\DurableTelegramBot;
use Bot\Bot\ExtendedApi;
use Bot\Config\TemporalConfig;
use Bot\Durability\CycleIdempotencyLedger;
use Bot\Durability\DurableCommandReplyGateway;
use Bot\Handler\ClearCommandHandler;
use Bot\Handler\WorkflowControlCommandHandler;
use Bot\Telegram\Factory;
use Bot\Telegram\TelegramChatAuthorizationPolicy;
use Bot\Telegram\Update;
use Cycle\ORM\EntityManager;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\ORMInterface;
use Phenogram\Bindings\Serializer;
use Phenogram\Bindings\Types\Interfaces\UpdateInterface;
use Phenogram\Framework\TelegramBot;
use Phenogram\Framework\TelegramBotApiClient;
use Spiral\Core\Container;
use Temporal\Client\GRPC\ServiceClient;
use Temporal\Client\WorkflowClient;

Dotenv\Dotenv::createUnsafeImmutable(__DIR__ . '/..')->safeLoad();

/** @var TemporalConfig $temporalConfig */
$temporalConfig = require __DIR__ . '/../config/temporal.php';
$botToken       = $temporalConfig->botToken;

$bot = new DurableTelegramBot(
    $botToken,
    api: new ExtendedApi(
        client: new TelegramBotApiClient($botToken),
        serializer: new Serializer(new Factory()),
    )
);

/** @var ORMInterface $orm */
/** @var Container $container */
[$container, $orm, $ormScope] = require __DIR__ . '/../config/orm.php';
$em                           = new EntityManager($orm);

$container->bind(EntityManagerInterface::class, $em);

$workflowClient = new WorkflowClient(
    serviceClient: ServiceClient::create($temporalConfig->temporalAddress),
    options: $temporalConfig->temporalClientOptions,
    converter: $temporalConfig->dataConverter
);

$agenticWorkflowHandler = new AgenticWorkflowHandler(
    client: $workflowClient,
);
$authorization  = new TelegramChatAuthorizationPolicy($bot->api);
$durableReplies = new DurableCommandReplyGateway(
    new CycleIdempotencyLedger($ormScope),
);
$clearCommandHandler = new ClearCommandHandler(
    $workflowClient,
    $authorization,
    $durableReplies,
);
$workflowControlCommandHandler = new WorkflowControlCommandHandler(
    $workflowClient,
    $authorization,
    $durableReplies,
);

$bot
    ->addHandler($clearCommandHandler)
    ->supports($clearCommandHandler::supports(...));

$bot
    ->addHandler($workflowControlCommandHandler)
    ->supports($workflowControlCommandHandler::supports(...));

$bot
    ->addHandler(function (UpdateInterface $update, TelegramBot $bot) use ($agenticWorkflowHandler) {
        if (!$update instanceof Update) {
            throw new UnexpectedValueException('Telegram update factory returned an unsupported update type.');
        }

        $paymentAnswer = $agenticWorkflowHandler->handleUpdate($update);

        if ($update->callbackQuery !== null) {
            try {
                $bot->api->answerCallbackQuery(callbackQueryId: $update->callbackQuery->id);
            } catch (Throwable $failure) {
                error_log('Unable to acknowledge Telegram callback query: ' . $failure->getMessage());
            }
        }

        $paymentAnswer?->send($bot->api);
    })
    ->supports(static fn (UpdateInterface $update): bool => !ClearCommandHandler::supports($update)
        && !WorkflowControlCommandHandler::supports($update));

$pressedCtrlC     = false;
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

    return;
};

pcntl_signal(SIGTERM, $gracefulShutdown);
pcntl_signal(SIGINT, $gracefulShutdown);
pcntl_async_signals(true);

$bot->run();
