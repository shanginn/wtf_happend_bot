<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Memory;

use Bot\Memory\ParticipantMemoryStore;
use Phenogram\Bindings\ApiInterface;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'ForgetMemoryExecutor.')]
class ForgetMemoryExecutor
{
    public function __construct(
        private readonly ParticipantMemoryStore $memoryStore,
        private readonly ApiInterface $api,
    ) {}

    #[ActivityMethod]
    public function execute(int $chatId, ForgetMemory $schema): string
    {
        $result = $this->memoryStore->forget(
            chatId: $chatId,
            request: $schema,
        );

        $notification = self::notificationText($result);

        if ($notification !== null) {
            $this->api->sendMessage($chatId, $notification);
        }

        return $result;
    }

    private static function notificationText(string $result): ?string
    {
        return str_starts_with($result, 'Memory forgotten') || str_contains($result, ' memories forgotten for ')
            ? 'Память удалена'
            : null;
    }
}
