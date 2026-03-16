<?php

declare(strict_types=1);

namespace Bot\Agent;

use Bot\Telegram\TelegramUpdateView;

interface UpdateTransformerInterface
{
    public function toChatUserMessage(TelegramUpdateView $view): object;
}
