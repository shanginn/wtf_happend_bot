<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Bot\Bot\ExtendedApi;
use Bot\Entity;
use Bot\Handler\IntelligentRoutingHandler;
use Bot\Handler\SaveUpdateHandler;
use Bot\Handler\StartCommandHandler;
use Bot\Handler\SummarizeCommandHandler;
use Bot\Middleware\OneMessageAtOneTimeMiddleware;
use Bot\Service\ChatService;
use Bot\Service\MemoryService;
use Bot\Tool\RouterTool;
use Cycle\ORM\EntityManager;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\ORMInterface;
use Phenogram\Bindings\Serializer;
use Phenogram\Framework\TelegramBot;
use Phenogram\Framework\TelegramBotApiClient;
use Shanginn\Openai\Openai;
use Shanginn\Openai\Openai\OpenaiClient;
use Shanginn\Openai\OpenaiSimple;
use Spiral\Core\Container;
use Mem0\Mem0;

Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

[
    'botToken'         => $botToken,
    'openrouterApiKey' => $openrouterApiKey,
    'deepseekApiKey'   => $deepseekApiKey,
    'mem0ApiKey'       => $mem0ApiKey,
] = require __DIR__ . '/../config/config.php';

$bot = new TelegramBot(
    $botToken,
    api: new ExtendedApi(
        client: new TelegramBotApiClient($botToken),
        serializer: new Serializer(),
    )
);

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
//$client            = new OpenaiClient(
//    apiKey: $deepseekApiKey,
//    apiUrl: 'https://api.deepseek.com'
//);
//$openai       = new Openai($client, 'deepseek-chat');
//$openaiSimple = new OpenaiSimple($openai);

$openaiSimple = OpenaiSimple::create(
    apiKey: $openrouterApiKey,
    model: 'moonshotai/kimi-k2',
    apiUrl: 'https://openrouter.ai/api/v1'
);

// Initialize memory service
$mem0 = new Mem0($mem0ApiKey);
$memoryService = new MemoryService($mem0);

// Initialize router tool for intelligent message routing
$routerTool = new RouterTool($openaiSimple);

$chatService  = new ChatService(
    $em,
    messages: $messageRepository,
    summarizationStates: $summarizationStateRepository,
    openaiSimple: $openaiSimple,
    memoryService: $memoryService,
);

$summarizeCommandHandler = new SummarizeCommandHandler($chatService, $summarizationStateRepository);
$intelligentRoutingHandler = new IntelligentRoutingHandler($routerTool, $chatService);

$bot->addHandler($saveUpdateHandler)
    ->supports($saveUpdateHandler::supports(...));

$bot->addHandler(new StartCommandHandler())
    ->supports(StartCommandHandler::supports(...));

$oneMessageAtATimeMiddleware = new OneMessageAtOneTimeMiddleware();

$bot->addHandler($summarizeCommandHandler)
    ->supports($summarizeCommandHandler::supports(...))
    ->middleware($oneMessageAtATimeMiddleware);

// Add intelligent routing handler for non-command messages
$bot->addHandler($intelligentRoutingHandler)
    ->supports($intelligentRoutingHandler::supports(...))
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
