<?php

declare(strict_types=1);

namespace Bot\Space\Runtime;

use Cycle\Database\DatabaseInterface;
use RuntimeException;
use Throwable;

/** Reads one complete command contract from the immutable snapshot pinned to the current batch. */
final readonly class SpaceCommandInspector
{
    public function __construct(private DatabaseInterface $database) {}

    public function inspect(
        string $snapshotId,
        string $spaceId,
        string $releaseId,
        string $name,
    ): string {
        $name = SpaceCommandBinding::normalizeName($name);
        if (preg_match('/\A[a-z0-9_]{1,32}\z/D', $name) !== 1) {
            throw new RuntimeException('Space command inspection requires a canonical command name.');
        }
        $row = $this->database->query(<<<'SQL'
            SELECT payload_json
            FROM space_runtime_snapshots
            WHERE id = ? AND space_id = ? AND release_id = ?
            SQL, [$snapshotId, $spaceId, $releaseId])->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('The pinned Space command snapshot is unavailable.');
        }

        try {
            $payload = json_decode((string) $row['payload_json'], true, flags: \JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            throw new RuntimeException('The pinned Space command snapshot is corrupt.', previous: $error);
        }
        if (!is_array($payload)
            || ($payload['snapshotId'] ?? null) !== $snapshotId
            || ($payload['spaceId'] ?? null) !== $spaceId
            || ($payload['releaseId'] ?? null) !== $releaseId
        ) {
            throw new RuntimeException('The pinned Space command snapshot identity is inconsistent.');
        }

        $commands = $payload['commands'] ?? null;
        if (!is_array($commands) || !array_is_list($commands)) {
            throw new RuntimeException('The pinned Space command registry is corrupt.');
        }
        foreach ($commands as $command) {
            if (!is_array($command) || ($command['name'] ?? null) !== $name) {
                continue;
            }
            $schema = $command['parametersSchema'] ?? null;
            if (!is_string($command['description'] ?? null)
                || !is_string($command['instructions'] ?? null)
                || !is_array($schema)
            ) {
                throw new RuntimeException("The pinned Space command /{$name} is corrupt.");
            }
            $binding = new SpaceCommandBinding(
                name: $name,
                description: $command['description'],
                instructions: $command['instructions'],
                parametersSchema: $schema,
            );

            $specification = [
                'command'          => $binding->name,
                'description'      => $binding->description,
                'instructions'     => $binding->instructions,
                'parametersSchema' => $binding->parametersSchema,
            ];

            return json_encode([
                ...$specification,
                'enabled'    => true,
                'releaseId'  => $releaseId,
                'specDigest' => 'sha256:' . hash('sha256', json_encode(
                    $specification,
                    \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
                )),
            ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        }

        return json_encode([
            'command'   => $name,
            'enabled'   => false,
            'releaseId' => $releaseId,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
}
