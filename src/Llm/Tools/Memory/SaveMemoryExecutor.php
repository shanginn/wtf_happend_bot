<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Memory;

use Bot\Memory\ParticipantMemoryStore;
use Phenogram\Bindings\ApiInterface;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'SaveMemoryExecutor.')]
class SaveMemoryExecutor
{
    public function __construct(
        private readonly ParticipantMemoryStore $memoryStore,
        private readonly ApiInterface $api,
    ) {}

    #[ActivityMethod]
    public function execute(int $chatId, SaveMemory $schema): string
    {
        $result = $this->memoryStore->save(
            chatId: $chatId,
            memory: $schema,
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
            str_starts_with($result, 'Memory saved') => 'Память добавлена',
            str_starts_with($result, 'Memory updated') => 'Память обновлена',
            str_starts_with($result, 'Memory unchanged') => 'Память уже актуальна',
            default => null,
        };
    }
}
