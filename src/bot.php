<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Bot\Entity;
use Bot\Handler\SaveUpdateHandler;
use Bot\Handler\StartCommandHandler;
use Bot\Handler\SummarizeCommandHandler;
use Bot\Middleware\OneMessageAtOneTimeMiddleware;
use Bot\Service\ChatService;
use Cycle\ORM\EntityManager;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\ORMInterface;
use Phenogram\Framework\TelegramBot;
use Shanginn\Openai\Openai;
use Shanginn\Openai\Openai\OpenaiClient;
use Shanginn\Openai\OpenaiSimple;
use Spiral\Core\Container;

Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

[
    'botToken'         => $botToken,
    'openrouterApiKey' => $openrouterApiKey,
    'deepseekApiKey'   => $deepseekApiKey,
] = require __DIR__ . '/../config/config.php';

$bot = new TelegramBot($botToken);

/** @var ORMInterface $orm */
/** @var Container $container */
[$container, $orm] = require __DIR__ . '/../config/orm.php';
$em                = new EntityManager($orm);

$container->bind(EntityManagerInterface::class, $em);

$messageRepository = $orm->getRepository(Entity\Message::class);
assert($messageRepository instanceof Entity\Message\MessageRepository);

$summarizationStateRepository = $orm->getRepository(Entity\SummarizationState::class);
assert($summarizationStateRepository instanceof Entity\SummarizationState\SummarizationStateRepository);

$saveUpdateHandler = new SaveUpdateHandler($em);
$client            = new OpenaiClient(
    apiKey: $deepseekApiKey,
    apiUrl: 'https://api.deepseek.com'
);
$openai       = new Openai($client, 'deepseek-chat');
$openaiSimple = new OpenaiSimple($openai);
$chatService  = new ChatService(
    $em,
    messages: $messageRepository,
    summarizationStates: $summarizationStateRepository,
    openaiSimple: $openaiSimple,
);

$summarizeCommandHandler = new SummarizeCommandHandler($chatService);

$bot->addHandler($saveUpdateHandler)
    ->supports($saveUpdateHandler::supports(...));

$bot->addHandler(new StartCommandHandler())
    ->supports(StartCommandHandler::supports(...));

$oneMessageAtATimeMiddleware = new OneMessageAtOneTimeMiddleware();

$bot->addHandler($summarizeCommandHandler)
    ->supports($summarizeCommandHandler::supports(...))
    ->middleware($oneMessageAtATimeMiddleware);

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
