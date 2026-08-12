<?php

declare(strict_types=1);

namespace Bot\Space\Dream;

use InvalidArgumentException;

final readonly class SpaceDreamInput
{
    public function __construct(
        public string $spaceId,
        public string $dreamDate,
        public ?string $baselineReleaseId = null,
        public DreamPolicy $policy = new DreamPolicy(),
        public ?string $executionToken = null,
        public ?string $executionChainToken = null,
        public int $executionAttempt = 0,
        public int $executionGeneration = 0,
    ) {
        if ($spaceId === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $dreamDate) !== 1) {
            throw new InvalidArgumentException('Space and dream date must be valid.');
        }
        if ($executionToken === null && $executionChainToken === null
            && $executionAttempt === 0 && $executionGeneration === 0
        ) {
            return;
        }
        if (!self::validToken($executionToken) || !self::validToken($executionChainToken)
            || $executionAttempt < 1 || $executionGeneration < 0
        ) {
            throw new InvalidArgumentException('Dream execution identity is invalid.');
        }
    }

    public function bindExecution(string $token, string $chainToken, int $attempt): self
    {
        if ($this->executionToken !== null || $this->executionGeneration !== 0) {
            throw new InvalidArgumentException('Dream execution identity is already bound.');
        }

        return new self(
            spaceId: $this->spaceId,
            dreamDate: $this->dreamDate,
            baselineReleaseId: $this->baselineReleaseId,
            policy: $this->policy,
            executionToken: $token,
            executionChainToken: $chainToken,
            executionAttempt: $attempt,
        );
    }

    public function claim(string $baselineReleaseId, int $generation): self
    {
        if ($this->executionToken === null || $this->executionGeneration !== 0 || $generation < 1) {
            throw new InvalidArgumentException('Only a bound Dream execution can acquire a generation.');
        }

        return new self(
            spaceId: $this->spaceId,
            dreamDate: $this->dreamDate,
            baselineReleaseId: $baselineReleaseId,
            policy: $this->policy,
            executionToken: $this->executionToken,
            executionChainToken: $this->executionChainToken,
            executionAttempt: $this->executionAttempt,
            executionGeneration: $generation,
        );
    }

    public function isBound(): bool
    {
        return $this->executionToken !== null;
    }

    public function isClaimed(): bool
    {
        return $this->executionGeneration > 0;
    }

    private static function validToken(?string $token): bool
    {
        return is_string($token)
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $token) === 1;
    }
}
