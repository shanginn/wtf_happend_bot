<?php

declare(strict_types=1);

namespace Tests\Bot;

use Bot\Handler\StartCommandHandler;
use Mockery;
use Mockery\MockInterface;
use Phenogram\Bindings\Api;
use Phenogram\Framework\Factories\ChatFactory;
use Phenogram\Framework\Factories\MessageEntityFactory;
use Phenogram\Framework\Factories\MessageFactory;
use Phenogram\Framework\Factories\UpdateFactory;
use Phenogram\Framework\Factories\UserFactory;
use Phenogram\Framework\TelegramBot;
use Tests\TestCase;

class StartCommandTest extends TestCase
{
    private TelegramBot $bot;

    /**
     * @var MockInterface<Api>
     */
    private Api|MockInterface $mockApi;

    public function setUp(): void
    {
        parent::setUp();

        $this->mockApi = Mockery::mock(Api::class);
        $this->bot     = new TelegramBot(
            'token',
            $this->mockApi,
        );

        $this->bot->addHandler(new StartCommandHandler())
            ->supports(StartCommandHandler::supports(...));
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