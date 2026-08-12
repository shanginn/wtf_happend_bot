<?php

declare(strict_types=1);

namespace Bot\Space\Operations;

use Throwable;

final readonly class HostReleaseActivationGate
{
    public function __construct(
        private HostReleaseStateStore $states,
        private int $pollIntervalMilliseconds = 2_000,
    ) {}

    public function await(string $releaseId): void
    {
        $attempt = 0;
        while (true) {
            ++$attempt;

            try {
                if ($this->states->isActive($releaseId)) {
                    return;
                }
                if ($attempt === 1 || $attempt % 30 === 0) {
                    error_log("Host release {$releaseId} is waiting for autonomous cutover authorization.");
                }
            } catch (Throwable $error) {
                error_log('Host release activation gate is waiting for persistence: ' . $error->getMessage());
            }

            usleep(max(100, $this->pollIntervalMilliseconds) * 1_000);
        }
    }
}
