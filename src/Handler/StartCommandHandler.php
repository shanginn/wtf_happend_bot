<?php

declare(strict_types=1);

namespace Bot\Handler;

use Phenogram\Bindings\Types\Interfaces\UpdateInterface;
use Phenogram\Framework\Handler\AbstractStartCommandHandler;
use Phenogram\Framework\TelegramBot;

class StartCommandHandler extends AbstractStartCommandHandler
{
    public function handle(UpdateInterface $update, TelegramBot $bot)
    {
        $bot->api->sendMessage(
            chatId: $update->message->chat->id,
            text: 'Привет'
        );
    }
}