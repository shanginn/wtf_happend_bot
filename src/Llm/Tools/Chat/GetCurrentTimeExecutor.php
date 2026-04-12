<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Chat;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'GetCurrentTimeExecutor.')]
class GetCurrentTimeExecutor
{
    #[ActivityMethod]
    public function execute(int $chatId, GetCurrentTime $schema): string
    {
        try {
            $timezone = new \DateTimeZone($schema->timezone);
        } catch (\Exception) {
            return "Unknown timezone: {$schema->timezone}. Use IANA timezone names like 'Europe/Moscow' or 'America/New_York'.";
        }

        $now = new \DateTimeImmutable('now', $timezone);

        return sprintf(
            'Current time in %s: %s (%s)',
            $schema->timezone,
            $now->format('Y-m-d H:i:s'),
            $now->format('l'),
        );
    }
}
