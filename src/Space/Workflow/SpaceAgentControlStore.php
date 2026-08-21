<?php

declare(strict_types=1);

namespace Bot\Space\Workflow;

use Cycle\Database\DatabaseInterface;
use RuntimeException;

/** Durable host control that survives Temporal continue-as-new and release cutovers. */
final readonly class SpaceAgentControlStore implements SpaceAgentControlStoreInterface
{
    public function __construct(private DatabaseInterface $database) {}

    public function isPaused(string $spaceId): bool
    {
        $row = $this->database->query(
            'SELECT agent_paused FROM agent_spaces WHERE id = ?',
            [$spaceId],
        )->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Cannot read agent control for an unknown Space.');
        }

        return self::databaseBoolean($row['agent_paused'] ?? null);
    }

    public function setPaused(string $spaceId, bool $paused): void
    {
        $updated = $this->database->execute(
            'UPDATE agent_spaces SET agent_paused = ?, updated_at = ? WHERE id = ?',
            [$paused, time(), $spaceId],
        );
        if ($updated !== 1) {
            throw new RuntimeException('Cannot update agent control for an unknown Space.');
        }
    }

    private static function databaseBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === '0') {
            return false;
        }
        if ($value === 1 || $value === '1') {
            return true;
        }

        throw new RuntimeException('Space agent control has an invalid paused flag.');
    }
}
