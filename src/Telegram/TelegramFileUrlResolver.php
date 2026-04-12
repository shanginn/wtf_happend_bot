<?php

declare(strict_types=1);

namespace Bot\Telegram;

use Phenogram\Bindings\ApiInterface;

class TelegramFileUrlResolver implements TelegramFileUrlResolverInterface
{
    /** @var array<string, string|null> */
    private array $cache = [];

    public function __construct(
        private readonly ApiInterface $api,
    ) {}

    public function resolve(string $fileId): ?string
    {
        if ($fileId === '') {
            return null;
        }

        if (array_key_exists($fileId, $this->cache)) {
            return $this->cache[$fileId];
        }

        $file = $this->api->getFile($fileId);

        if ($file->filePath === null || $file->filePath === '') {
            return null;
        }

        return $this->cache[$fileId] = sprintf(
            'https://api.telegram.org/file/bot%s/%s',
            $_ENV['TELEGRAM_BOT_TOKEN'] ?? '',
            ltrim($file->filePath, '/'),
        );
    }
}
