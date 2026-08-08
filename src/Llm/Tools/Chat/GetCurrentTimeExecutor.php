<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Chat;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

final class GetCurrentTimeExecutor
{
    public function execute(string $timezoneName = 'UTC'): string
    {
        try {
            $timezone = new DateTimeZone($timezoneName);
        } catch (Exception) {
            return "Unknown timezone: {$timezoneName}. Use IANA timezone names like 'Europe/Moscow' or 'America/New_York'.";
        }

        $now = new DateTimeImmutable('now', $timezone);

        return sprintf(
            'Current time in %s: %s (%s)',
            $timezoneName,
            $now->format('Y-m-d H:i:s'),
            $now->format('l'),
        );
    }
}
