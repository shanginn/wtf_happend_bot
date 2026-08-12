<?php

declare(strict_types=1);

namespace Bot\Space\Dream;

use Bot\Config\TemporalExecutionIdentity;
use InvalidArgumentException;

final readonly class DreamCoordinatorInput
{
    public function __construct(
        public ?string $dreamDate = null,
        public string $timeZone = 'Asia/Yekaterinburg',
        public int $batchSize = 25,
        public int $maximumConcurrentDreams = 4,
        public DreamPolicy $policy = new DreamPolicy(),
        public string $hostReleaseId = 'local',
    ) {
        TemporalExecutionIdentity::assertReleaseId($hostReleaseId);
        if ($dreamDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dreamDate) !== 1) {
            throw new InvalidArgumentException('Dream date must use YYYY-MM-DD.');
        }
        if (!in_array($timeZone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException('Dream time zone must be a valid IANA identifier.');
        }
        if ($batchSize < 1 || $maximumConcurrentDreams < 1) {
            throw new InvalidArgumentException('Dream coordinator limits must be positive.');
        }
    }
}
