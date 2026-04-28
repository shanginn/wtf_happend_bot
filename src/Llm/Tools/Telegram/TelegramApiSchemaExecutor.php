<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Telegram;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'TelegramApiSchemaExecutor.')]
class TelegramApiSchemaExecutor
{
    public function __construct(
        private readonly TelegramApiMethodCatalog $catalog = new TelegramApiMethodCatalog(),
    ) {}

    #[ActivityMethod]
    public function execute(int $chatId, TelegramApiSchema $schema): string
    {
        if ($schema->method !== null && trim($schema->method) !== '') {
            $method = $this->catalog->method($schema->method);

            if ($method === null) {
                return sprintf(
                    'Unknown Telegram Bot API method "%s". Similar methods: %s',
                    $schema->method,
                    implode(', ', $this->catalog->similarMethods($schema->method)),
                );
            }

            return $this->catalog->describeMethod($method);
        }

        return $this->catalog->search($schema->query, $schema->limit);
    }
}
