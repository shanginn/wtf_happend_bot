<?php

declare(strict_types=1);

namespace Bot\Telegram;

use Phenogram\Bindings\ApiInterface;
use Phenogram\Framework\TelegramBot;
use Throwable;

class TelegramFileUrlResolver implements TelegramFileUrlResolverInterface
{
    /** @var array<string, string|null> */
    private array $cache = [];

    private string $telegramBotToken;

    public function __construct(
        private readonly TelegramBot $bot,
    ) {
        $this->telegramBotToken = $this->bot->getToken();
    }

    public function resolve(string $fileId): ?string
    {
        if ($fileId === '') {
            return null;
        }

        if (array_key_exists($fileId, $this->cache)) {
            return $this->cache[$fileId];
        }

        $file = $this->bot->api->getFile($fileId);

        if ($file->filePath === null || $file->filePath === '') {
            return null;
        }

        return $this->cache[$fileId] = sprintf(
            'https://api.telegram.org/file/bot%s/%s',
            $this->telegramBotToken,
            ltrim($file->filePath, '/'),
        );
    }
}
