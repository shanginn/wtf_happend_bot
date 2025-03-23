<?php

declare(strict_types=1);

namespace Tests\Bot;

use Bot\Entity\Message\MessageRepository;
use Bot\Entity\SummarizationState\SummarizationStateRepository;
use Bot\Handler\SaveUpdateHandler;
use Bot\Handler\StartCommandHandler;
use Bot\Handler\SummarizeCommandHandler;
use Bot\Service\ChatService;
use Cycle\ORM\EntityManager;
use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\ORMInterface;
use Mockery;
use Mockery\MockInterface;
use Phenogram\Bindings\Api;
use Phenogram\Framework\Factories\ChatFactory;
use Phenogram\Framework\Factories\MessageEntityFactory;
use Phenogram\Framework\Factories\MessageFactory;
use Phenogram\Framework\Factories\UpdateFactory;
use Phenogram\Framework\Factories\UserFactory;
use Phenogram\Framework\TelegramBot;
use Shanginn\Openai\Openai;
use Shanginn\Openai\OpenaiSimple;
use Spiral\Core\Container;
use Tests\TestCase;
use Bot\Entity;

class SummarizeCommandTest extends TestCase
{
    private TelegramBot $bot;

    /**
     * @var MockInterface<Api>
     */
    private Api|MockInterface $mockApi;
    private MessageRepository $messageRepository;
    private SummarizationStateRepository $summarizationStateRepository;

    /**
     * @var MockInterface<OpenaiSimple>
     */
    private OpenaiSimple|MockInterface $mockOpenai;

    public function setUp(): void
    {
        parent::setUp();

        $this->mockApi = Mockery::mock(Api::class);
        $this->bot     = new TelegramBot(
            'token',
            $this->mockApi,
        );

        /** @var ORMInterface $orm */
        /** @var Container $container */
        [$container, $orm] = require __DIR__ . '/../../config/orm.php';
        $em                = new EntityManager($orm);

        $container->bind(EntityManagerInterface::class, $em);

        $this->messageRepository = $orm->getRepository(Entity\Message::class);
        assert($this->messageRepository instanceof MessageRepository);

        $this->summarizationStateRepository = $orm->getRepository(Entity\SummarizationState::class);
        assert($this->summarizationStateRepository instanceof SummarizationStateRepository);

        $saveUpdateHandler = new SaveUpdateHandler($em);
        $this->mockOpenai = Mockery::mock(OpenaiSimple::class);

        $chatService       = new ChatService(
            $em,
            messages: $this->messageRepository,
            summarizationStates: $this->summarizationStateRepository,
            openaiSimple: $this->mockOpenai,
        );

        $summarizeCommandHandler = new SummarizeCommandHandler($chatService);

        $this->bot->addHandler($saveUpdateHandler)
            ->supports($saveUpdateHandler::supports(...));

        $this->bot->addHandler($summarizeCommandHandler)
            ->supports($summarizeCommandHandler::supports(...));
    }

    public function testSaveUpdateMessage()
    {
        $userId = self::faker()->randomNumber();
        $text = self::faker()->sentence();

        $update = UpdateFactory::make(
            message: MessageFactory::make(
                chat: ChatFactory::make(
                    id: $userId,
                ),
                text: $text,
                from: UserFactory::make(
                    id: $userId,
                ),
            ),
        );

        $this->bot->handleUpdate($update)[0]->await();

        self::assertCount(1, $this->messageRepository->findAll([
            'chat_id' => $userId,
        ]));
    }

    public function testWtfCommand()
    {
        $userId = self::faker()->randomNumber();

        $update = UpdateFactory::make(
            message: MessageFactory::make(
                chat: ChatFactory::make(
                    id: $userId,
                ),
                text: '/wtf',
                entities: [
                    MessageEntityFactory::make(
                        type: 'bot_command',
                        offset: 0,
                        length: 4
                    ),
                ],
                from: UserFactory::make(
                    id: $userId,
                ),
            ),
        );

        $this->mockApi->shouldReceive('sendChatAction')
            ->once()
            ->withArgs(
                fn ($chatId, $action) => $chatId === $userId && $action === 'typing'
            );

        $this->mockApi->shouldReceive('sendMessage')
            ->once()
            ->withArgs(
                fn ($chatId, $text) => $chatId === $userId && $text === 'Не найдено достаточно сообщений для обработки, читайте сами.'
            )
            ->andReturn(MessageFactory::make());

        $this->bot->handleUpdate($update)[0]->await();

        Mockery::close();

        self::assertTrue(true);
    }

    public function testSendMessagesAndThenSummarizeWithWtfCommand()
    {
        $userId = self::faker()->randomNumber();

        $update = UpdateFactory::make(
            message: MessageFactory::make(
                chat: ChatFactory::make(
                    id: $userId,
                ),
                text: self::faker()->sentence(),
                from: UserFactory::make(
                    id: $userId,
                ),
            ),
        );

        $this->bot->handleUpdate($update)[0]->await();

        $update = UpdateFactory::make(
            message: MessageFactory::make(
                chat: ChatFactory::make(
                    id: $userId,
                ),
                text: self::faker()->sentence(),
                from: UserFactory::make(
                    id: $userId,
                ),
            ),
        );

        $this->bot->handleUpdate($update)[0]->await();

        $update = UpdateFactory::make(
            message: MessageFactory::make(
                chat: ChatFactory::make(
                    id: $userId,
                ),
                text: '/wtf',
                entities: [
                    MessageEntityFactory::make(
                        type: 'bot_command',
                        offset: 0,
                        length: 4
                    ),
                ],
                from: UserFactory::make(
                    id: $userId,
                ),
            ),
        );

        $this->mockApi->shouldReceive('sendChatAction')
            ->once()
            ->withArgs(
                fn ($chatId, $action) => $chatId === $userId && $action === 'typing'
            );


        $this->mockOpenai->shouldReceive('generate')
            ->once()
            ->andReturn($summary = self::faker()->text());

        $this->mockApi->shouldReceive('sendMessage')
            ->once()
            ->withArgs(
                fn ($chatId, $text) => $chatId === $userId && $text === $summary
            )
            ->andReturn(MessageFactory::make());

        $this->bot->handleUpdate($update)[0]->await();

        Mockery::close();

        self::assertTrue(true);
    }

    public function testStartCommand()
    {
        $userId = self::faker()->randomNumber();

        $update = UpdateFactory::make(
            message: MessageFactory::make(
                chat: ChatFactory::make(
                    id: $userId,
                ),
                text: '/start',
                entities: [
                    MessageEntityFactory::make(
                        type: 'bot_command',
                        offset: 0,
                        length: 6
                    ),
                ],
                from: UserFactory::make(
                    id: $userId,
                ),
            ),
        );

        $this->mockApi->shouldReceive('sendChatAction')
            ->once()
            ->withArgs(
                fn ($chatId, $action) => $chatId === $userId && $action === 'typing'
            );

        $this->mockApi->shouldReceive('sendMessage')
            ->once()
            ->withArgs(
                fn ($chatId, $text) => $chatId === $userId && $text === 'Привет'
            )
            ->andReturn(MessageFactory::make());

        $this->bot->handleUpdate($update)[0]->await();

        self::assertTrue(true);
    }
}