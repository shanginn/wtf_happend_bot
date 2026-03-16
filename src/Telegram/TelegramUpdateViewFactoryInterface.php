<?php

declare(strict_types=1);

namespace Bot\Telegram;

use Phenogram\Bindings\Types\Interfaces\UpdateInterface;

interface TelegramUpdateViewFactoryInterface
{
    public function create(UpdateInterface $update): TelegramUpdateView;
}
