<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Memory;

use Bot\Memory\ParticipantMemoryStore;
use Phenogram\Bindings\ApiInterface;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'UpdateMemoryExecutor.')]
class UpdateMemoryExecutor
{
    public function __construct(
        private readonly ParticipantMemoryStore $memoryStore,
        private readonly ApiInterface $api,
    ) {}

    #[ActivityMethod]
    public function execute(int $chatId, UpdateMemory $schema): string
    {
        $result = $this->memoryStore->update(
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
        return match (true) {
            str_starts_with($result, 'Memory updated') => 'Память обновлена',
            str_starts_with($result, 'Memory unchanged') => 'Память уже актуальна',
            default => null,
        };
    }
}
