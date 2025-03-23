<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Bot\Handler\SaveUpdateHandler;
use Bot\Handler\StartCommandHandler;
use Cycle\ORM\EntityManager;
use Cycle\ORM\ORMInterface;
use Phenogram\Framework\TelegramBot;
use Bot\Entity;

Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load();

[
    'botToken'                 => $botToken,
    'openrouterApiKey'         => $openrouterApiKey,
    'systemPrompt'              => $systemPrompt,
    'finalSystemPromptTemplate' => $finalSystemPromptTemplate,
] = require __DIR__ . '/../config/config.php';

$bot = new TelegramBot($botToken);

/** @var ORMInterface $orm */
$orm = require __DIR__ . '/../config/orm.php';
$em  = new EntityManager($orm);

/** @var Entity\Message[] $messages */
$messages = $orm->getRepository(Entity\Message::class)->findAll();

dump($messages);

$saveUpdateHandler = new SaveUpdateHandler($em);

$bot->addHandler($saveUpdateHandler)
    ->supports($saveUpdateHandler::supports(...));

$bot->addHandler(new StartCommandHandler())
    ->supports(StartCommandHandler::supports(...));

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

    exit(0);
};

pcntl_signal(SIGTERM, $gracefulShutdown);
pcntl_signal(SIGINT, $gracefulShutdown);

$bot->run();
