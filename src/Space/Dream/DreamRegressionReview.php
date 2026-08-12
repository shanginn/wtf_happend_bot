<?php

declare(strict_types=1);

namespace Bot\Space\Dream;

use InvalidArgumentException;

final readonly class DreamRegressionReview
{
    public const string STATUS_STABLE            = 'stable';
    public const string STATUS_OBSERVING         = 'observing';
    public const string STATUS_ROLLED_BACK       = 'rolled-back';
    public const string STATUS_ROLLBACK_DEFERRED = 'rollback-deferred';

    public function __construct(
        public string $status,
        public string $fromReleaseId,
        public ?string $toReleaseId,
        public ?string $evaluationDigest,
        public string $reason,
    ) {
        if (!in_array($status, [
            self::STATUS_STABLE,
            self::STATUS_OBSERVING,
            self::STATUS_ROLLED_BACK,
            self::STATUS_ROLLBACK_DEFERRED,
        ], true)) {
            throw new InvalidArgumentException('Dream regression review has an invalid status.');
        }
        if ($fromReleaseId === '' || trim($reason) === '') {
            throw new InvalidArgumentException('Dream regression review requires a release and reason.');
        }
        if (in_array($status, [self::STATUS_STABLE, self::STATUS_OBSERVING], true)
            && $toReleaseId !== null
        ) {
            throw new InvalidArgumentException('A stable regression review cannot name a rollback target.');
        }
        if (in_array($status, [self::STATUS_ROLLED_BACK, self::STATUS_ROLLBACK_DEFERRED], true)
            && ($toReleaseId === null || $evaluationDigest === null)
        ) {
            throw new InvalidArgumentException(
                'A terminal regression review requires a target and evaluation digest.',
            );
        }
    }

    public function stopsDream(): bool
    {
        return $this->status !== self::STATUS_STABLE;
    }
}
