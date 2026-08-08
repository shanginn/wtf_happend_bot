<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Telegram;

final class TelegramApiSchemaExecutor
{
    public function __construct(
        private readonly TelegramApiMethodCatalog $catalog = new TelegramApiMethodCatalog(),
    ) {}

    public function execute(?string $methodName = null, ?string $query = null, int $limit = 20): string
    {
        if ($methodName !== null && trim($methodName) !== '') {
            $method = $this->catalog->method($methodName);

            if ($method === null) {
                return sprintf(
                    'Unknown Telegram Bot API method "%s". Similar methods: %s',
                    $methodName,
                    implode(', ', $this->catalog->similarMethods($methodName)),
                );
            }

            return $this->catalog->describeMethod($method);
        }

        return $this->catalog->search($query, $limit);
    }
}
