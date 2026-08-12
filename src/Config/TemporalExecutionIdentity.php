<?php

declare(strict_types=1);

namespace Bot\Config;

use InvalidArgumentException;

/**
 * Release-qualified Temporal names prevent a new host from replaying workflow
 * history that was produced by code from an older release.
 */
final readonly class TemporalExecutionIdentity
{
    private const string RELEASE_PATTERN = '/^(?:local|[a-f0-9]{64})$/D';

    public string $agentTaskQueue;
    public string $dreamTaskQueue;

    public function __construct(
        public string $hostReleaseId,
        string $agentTaskQueue,
        string $dreamTaskQueue,
    ) {
        self::assertReleaseId($hostReleaseId);

        $this->agentTaskQueue = self::qualifyTaskQueue($agentTaskQueue, $hostReleaseId);
        $this->dreamTaskQueue = self::qualifyTaskQueue($dreamTaskQueue, $hostReleaseId);
    }

    public static function assertReleaseId(string $releaseId): void
    {
        if (preg_match(self::RELEASE_PATTERN, $releaseId) !== 1) {
            throw new InvalidArgumentException(
                'HOST_RELEASE_ID must be an immutable lowercase 64-character SHA-256 hex digest '
                . '(`local` is reserved for development).',
            );
        }
    }

    public static function qualifyTaskQueue(string $taskQueue, string $releaseId): string
    {
        self::assertReleaseId($releaseId);
        $taskQueue = trim($taskQueue);
        if ($taskQueue === '') {
            throw new InvalidArgumentException('Temporal task queue must not be empty.');
        }

        $suffix    = "-{$releaseId}";
        $qualified = str_ends_with($taskQueue, $suffix)
            ? $taskQueue
            : $taskQueue . $suffix;

        if (strlen($qualified) > 255 || preg_match('/^[a-zA-Z0-9._-]+$/D', $qualified) !== 1) {
            throw new InvalidArgumentException('Temporal task queue contains unsafe characters or is too long.');
        }

        return $qualified;
    }

    public static function spaceAgentWorkflowId(string $spaceId, string $releaseId): string
    {
        self::assertReleaseId($releaseId);

        return "space-agent/{$spaceId}/v1/release/{$releaseId}";
    }

    public static function dreamCoordinatorWorkflowId(string $releaseId): string
    {
        self::assertReleaseId($releaseId);

        return "dream-coordinator-v1/release/{$releaseId}";
    }

    public static function spaceDreamWorkflowId(string $spaceId, string $dreamDate, string $releaseId): string
    {
        self::assertReleaseId($releaseId);

        return "space-dream/{$spaceId}/{$dreamDate}/release/{$releaseId}";
    }

    public static function belongsToRelease(string $workflowId, string $releaseId): bool
    {
        self::assertReleaseId($releaseId);

        return str_ends_with($workflowId, "/release/{$releaseId}");
    }
}
