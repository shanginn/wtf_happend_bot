<?php

declare(strict_types=1);

namespace Bot\Space\Persistence;

use Bot\Entity\SpaceMemoryVersion;
use Cycle\Database\DatabaseInterface;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Append-only Space memory. Corrections and forgetting create new revisions.
 */
final readonly class SpaceMemoryStore
{
    public function __construct(
        private SpaceStore $spaces,
        private DatabaseInterface $database,
    ) {}

    public function append(
        string $spaceId,
        string $participantKey,
        string $participantLabel,
        string $memory,
        string $quote,
        string $context,
        string $provenanceJson = '{}',
        ?int $confidencePermille = null,
        ?string $idempotencyKey = null,
        ?int $now = null,
    ): SpaceMemoryVersion {
        return $this->writeVersion(
            spaceId: $spaceId,
            participantKey: $participantKey,
            participantLabel: $participantLabel,
            memory: $memory,
            quote: $quote,
            context: $context,
            status: SpaceMemoryVersion::STATUS_ACTIVE,
            provenanceJson: $provenanceJson,
            confidencePermille: $confidencePermille,
            idempotencyKey: $idempotencyKey,
            now: $now,
        );
    }

    public function update(
        string $spaceId,
        string $memoryId,
        string $participantKey,
        string $participantLabel,
        string $memory,
        string $quote,
        string $context,
        string $provenanceJson = '{}',
        ?int $confidencePermille = null,
        ?string $idempotencyKey = null,
        ?int $now = null,
        ?int $expectedMemoryRevision = null,
    ): SpaceMemoryVersion {
        return $this->writeVersion(
            spaceId: $spaceId,
            participantKey: $participantKey,
            participantLabel: $participantLabel,
            memory: $memory,
            quote: $quote,
            context: $context,
            status: SpaceMemoryVersion::STATUS_ACTIVE,
            provenanceJson: $provenanceJson,
            confidencePermille: $confidencePermille,
            idempotencyKey: $idempotencyKey,
            supersedesMemoryId: $memoryId,
            now: $now,
            expectedMemoryRevision: $expectedMemoryRevision,
        );
    }

    public function forget(
        string $spaceId,
        string $memoryId,
        string $provenanceJson = '{}',
        ?string $idempotencyKey = null,
        ?int $now = null,
        ?int $expectedMemoryRevision = null,
    ): SpaceMemoryVersion {
        $target = $this->findRaw($spaceId, $memoryId)
            ?? throw new RuntimeException('The Space memory to forget was not found.');

        return $this->writeVersion(
            spaceId: $spaceId,
            participantKey: $target->participantKey,
            participantLabel: $target->participantLabel,
            memory: $target->memory,
            quote: $target->quote,
            context: $target->context,
            status: SpaceMemoryVersion::STATUS_FORGOTTEN,
            provenanceJson: $provenanceJson,
            confidencePermille: $target->confidencePermille,
            idempotencyKey: $idempotencyKey,
            supersedesMemoryId: $memoryId,
            now: $now,
            expectedMemoryRevision: $expectedMemoryRevision,
        );
    }

    /**
     * Atomically forget an exact target set selected from one pinned memory revision.
     *
     * @param list<array{memoryId: string, provenanceJson: string, idempotencyKey: string}> $requests
     * @param string                                                                        $spaceId
     * @param int                                                                           $expectedMemoryRevision
     * @param ?int                                                                          $now
     *
     * @return list<SpaceMemoryVersion>
     */
    public function forgetMany(
        string $spaceId,
        array $requests,
        int $expectedMemoryRevision,
        ?int $now = null,
    ): array {
        SpaceId::assert($spaceId);
        if ($expectedMemoryRevision < 0) {
            throw new InvalidArgumentException('Expected memory revision must not be negative.');
        }
        if (!array_is_list($requests) || count($requests) > 100) {
            throw new InvalidArgumentException('Atomic memory forget requests must be a bounded list.');
        }
        if ($requests === []) {
            return [];
        }

        $memoryIds = [];
        $keys      = [];
        foreach ($requests as $request) {
            if (!is_array($request)) {
                throw new InvalidArgumentException('Atomic memory forget request must be an object.');
            }
            $memoryId       = self::required((string) ($request['memoryId'] ?? ''), 'memory id');
            $idempotencyKey = self::required(
                (string) ($request['idempotencyKey'] ?? ''),
                'memory idempotency key',
            );
            $provenanceJson = (string) ($request['provenanceJson'] ?? '');
            self::assertJson($provenanceJson);
            if (isset($memoryIds[$memoryId]) || isset($keys[$idempotencyKey])) {
                throw new InvalidArgumentException('Atomic memory forget targets and keys must be unique.');
            }
            $memoryIds[$memoryId]  = true;
            $keys[$idempotencyKey] = true;
        }

        $now ??= time();

        return $this->database->transaction(function (DatabaseInterface $database) use (
            $spaceId,
            $requests,
            $expectedMemoryRevision,
            $now,
        ): array {
            $spaceRow = $database->query(<<<'SQL'
                SELECT memory_revision
                FROM agent_spaces
                WHERE id = ? AND status = 'active'
                FOR UPDATE
                SQL, [$spaceId])->fetch();
            if (!is_array($spaceRow)) {
                throw new RuntimeException('Cannot write memory for a missing or disabled Space.');
            }

            $existing = [];
            foreach ($requests as $index => $request) {
                $record = $this->findRawByIdempotencyKey(
                    $spaceId,
                    $request['idempotencyKey'],
                    $database,
                );
                if ($record !== null) {
                    $existing[$index] = $record;
                }
            }
            if ($existing !== []) {
                if (count($existing) !== count($requests)) {
                    throw new RuntimeException('Atomic memory forget was only partially persisted.');
                }
                foreach ($requests as $index => $request) {
                    $record         = $existing[$index];
                    $originalStatus = $record->status === SpaceMemoryVersion::STATUS_SUPERSEDED
                        ? SpaceMemoryVersion::STATUS_FORGOTTEN
                        : $record->status;
                    if ($originalStatus !== SpaceMemoryVersion::STATUS_FORGOTTEN
                        || $record->supersedesMemoryId !== $request['memoryId']
                        || $record->provenanceJson !== $request['provenanceJson']
                    ) {
                        throw new RuntimeException(
                            'Atomic memory forget idempotency key names a different mutation.',
                        );
                    }
                }

                return array_values($existing);
            }

            if ((int) $spaceRow['memory_revision'] !== $expectedMemoryRevision) {
                throw new RuntimeException('The pinned Space memory revision is stale.');
            }

            $targets = [];
            foreach ($requests as $index => $request) {
                $target = $database->query(<<<'SQL'
                    SELECT *
                    FROM space_memory_versions
                    WHERE id = ? AND space_id = ? AND status = 'active'
                    FOR UPDATE
                    SQL, [$request['memoryId'], $spaceId])->fetch();
                if (!is_array($target)) {
                    throw new RuntimeException('An active Space memory selected for atomic forget is stale.');
                }
                $targets[$index] = self::hydrate($target);
            }

            $records = [];
            foreach ($requests as $index => $request) {
                $target    = $targets[$index];
                $records[] = $this->writeVersion(
                    spaceId: $spaceId,
                    participantKey: $target->participantKey,
                    participantLabel: $target->participantLabel,
                    memory: $target->memory,
                    quote: $target->quote,
                    context: $target->context,
                    status: SpaceMemoryVersion::STATUS_FORGOTTEN,
                    provenanceJson: $request['provenanceJson'],
                    confidencePermille: $target->confidencePermille,
                    idempotencyKey: $request['idempotencyKey'],
                    supersedesMemoryId: $target->id,
                    now: $now,
                    expectedMemoryRevision: $expectedMemoryRevision + $index,
                );
            }

            return $records;
        });
    }

    /**
     * @param string  $spaceId
     * @param ?string $participantKey
     * @param int     $limit
     * @param ?int    $atRevision
     *
     * @return array<SpaceMemoryVersion>
     */
    public function recall(
        string $spaceId,
        ?string $participantKey = null,
        int $limit = 20,
        ?int $atRevision = null,
    ): array {
        $limit = max(1, min($limit, 100));

        if ($atRevision !== null) {
            if ($atRevision < 0) {
                throw new InvalidArgumentException('Memory revision must not be negative.');
            }
            $participantClause = '';
            $parameters        = [$spaceId, $atRevision];
            if ($participantKey !== null) {
                $participantClause = 'AND current.participant_key = ?';
                $parameters[]      = $participantKey;
            }
            $parameters[] = $atRevision;
            $parameters[] = $limit;
            $rows         = $this->database->query(self::historicalRecallSql($participantClause), $parameters)->fetchAll();

            return array_values(array_map(
                static fn (array $row): SpaceMemoryVersion => self::hydrate($row),
                array_filter($rows, 'is_array'),
            ));
        }

        $participantClause = '';
        $parameters        = [$spaceId];
        if ($participantKey !== null) {
            $participantClause = 'AND participant_key = ?';
            $parameters[]      = $participantKey;
        }
        $parameters[] = $limit;
        $rows         = $this->database->query(<<<SQL
            SELECT *
            FROM space_memory_versions
            WHERE space_id = ?
                AND status = 'active'
                {$participantClause}
            ORDER BY revision DESC
            LIMIT ?
            SQL, $parameters)->fetchAll();

        return array_values(array_map(
            static fn (array $row): SpaceMemoryVersion => self::hydrate($row),
            array_filter($rows, 'is_array'),
        ));
    }

    public function byIdempotencyKey(string $spaceId, string $idempotencyKey): ?SpaceMemoryVersion
    {
        SpaceId::assert($spaceId);
        $idempotencyKey = self::required($idempotencyKey, 'memory idempotency key');

        return $this->findRawByIdempotencyKey($spaceId, $idempotencyKey, $this->database);
    }

    /**
     * @param string $spaceId
     * @param string $idempotencyPrefix
     *
     * @return array<SpaceMemoryVersion>
     */
    public function byIdempotencyPrefix(string $spaceId, string $idempotencyPrefix): array
    {
        SpaceId::assert($spaceId);
        $idempotencyPrefix = self::required($idempotencyPrefix, 'memory idempotency prefix');
        $rows              = $this->database->query(<<<'SQL'
            SELECT *
            FROM space_memory_versions
            WHERE space_id = ?
                AND idempotency_key IS NOT NULL
                AND left(idempotency_key, length(?)) = ?
            ORDER BY revision ASC
            SQL, [$spaceId, $idempotencyPrefix, $idempotencyPrefix])->fetchAll();

        return array_values(array_map(
            static fn (array $row): SpaceMemoryVersion => self::hydrate($row),
            array_filter($rows, 'is_array'),
        ));
    }

    public function byId(string $spaceId, string $memoryId): ?SpaceMemoryVersion
    {
        SpaceId::assert($spaceId);

        return $this->findRaw($spaceId, $memoryId);
    }

    private static function historicalRecallSql(string $participantClause): string
    {
        return <<<SQL
                SELECT
                    current.*,
                    CASE
                        WHEN current.status = 'superseded' THEN 'active'
                        ELSE current.status
                    END AS effective_status
                FROM space_memory_versions AS current
                WHERE current.space_id = ?
                    AND current.revision <= ?
                    AND current.status <> 'forgotten'
                    {$participantClause}
                    AND NOT EXISTS (
                        SELECT 1
                        FROM space_memory_versions AS newer
                        WHERE newer.space_id = current.space_id
                            AND newer.supersedes_memory_id = current.id
                            AND newer.revision <= ?
                    )
                ORDER BY current.revision DESC
                LIMIT ?
            SQL;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): SpaceMemoryVersion
    {
        return new SpaceMemoryVersion(
            id: (string) $row['id'],
            spaceId: (string) $row['space_id'],
            revision: (int) $row['revision'],
            participantKey: (string) $row['participant_key'],
            participantLabel: (string) $row['participant_label'],
            memory: (string) $row['memory'],
            quote: (string) $row['quote'],
            context: (string) $row['context'],
            status: (string) ($row['effective_status'] ?? $row['status']),
            idempotencyKey: $row['idempotency_key'] === null ? null : (string) $row['idempotency_key'],
            supersedesMemoryId: $row['supersedes_memory_id'] === null
                ? null
                : (string) $row['supersedes_memory_id'],
            provenanceJson: (string) $row['provenance_json'],
            confidencePermille: $row['confidence_permille'] === null
                ? null
                : (int) $row['confidence_permille'],
            createdAt: (int) $row['created_at'],
            sourceUpdatedAt: (int) $row['source_updated_at'],
        );
    }

    private static function required(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException(sprintf('Space %s must not be empty.', $label));
        }

        return $value;
    }

    private static function optional(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function assertJson(string $json): void
    {
        try {
            json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Memory provenance must be valid JSON.',
                previous: $exception,
            );
        }
    }

    private function writeVersion(
        string $spaceId,
        string $participantKey,
        string $participantLabel,
        string $memory,
        string $quote,
        string $context,
        string $status,
        string $provenanceJson,
        ?int $confidencePermille,
        ?string $idempotencyKey,
        ?string $supersedesMemoryId = null,
        ?int $now = null,
        ?int $expectedMemoryRevision = null,
    ): SpaceMemoryVersion {
        SpaceId::assert($spaceId);
        $participantKey   = self::required($participantKey, 'participant key');
        $participantLabel = self::required($participantLabel, 'participant label');
        $memory           = self::required($memory, 'memory');
        $quote            = self::required($quote, 'quote');
        $context          = self::required($context, 'context');
        $idempotencyKey   = self::optional($idempotencyKey);
        self::assertJson($provenanceJson);

        if ($confidencePermille !== null && ($confidencePermille < 0 || $confidencePermille > 1000)) {
            throw new InvalidArgumentException('Memory confidence must be between 0 and 1000.');
        }
        if ($expectedMemoryRevision !== null && $expectedMemoryRevision < 0) {
            throw new InvalidArgumentException('Expected memory revision must not be negative.');
        }

        $now ??= time();

        return $this->database->transaction(function (DatabaseInterface $database) use (
            $spaceId,
            $participantKey,
            $participantLabel,
            $memory,
            $quote,
            $context,
            $status,
            $provenanceJson,
            $confidencePermille,
            $idempotencyKey,
            $supersedesMemoryId,
            $now,
            $expectedMemoryRevision,
        ): SpaceMemoryVersion {
            $spaceRow = $database->query(<<<'SQL'
                SELECT memory_revision
                FROM agent_spaces
                WHERE id = ? AND status = 'active'
                FOR UPDATE
                SQL, [$spaceId])->fetch();

            if (!is_array($spaceRow)) {
                throw new RuntimeException('Cannot write memory for a missing or disabled Space.');
            }

            if ($idempotencyKey !== null) {
                $existing = $this->findRawByIdempotencyKey($spaceId, $idempotencyKey, $database);
                if ($existing !== null) {
                    $existingOriginalStatus = $existing->status === SpaceMemoryVersion::STATUS_SUPERSEDED
                        ? SpaceMemoryVersion::STATUS_ACTIVE
                        : $existing->status;
                    if ($existing->participantKey !== $participantKey
                        || $existing->participantLabel !== $participantLabel
                        || $existing->memory !== $memory
                        || $existing->quote !== $quote
                        || $existing->context !== $context
                        || $existingOriginalStatus !== $status
                        || $existing->supersedesMemoryId !== $supersedesMemoryId
                        || $existing->provenanceJson !== $provenanceJson
                        || $existing->confidencePermille !== $confidencePermille
                    ) {
                        throw new RuntimeException('Memory idempotency key already names a different mutation.');
                    }

                    return $existing;
                }
            }

            if ($expectedMemoryRevision !== null
                && (int) $spaceRow['memory_revision'] !== $expectedMemoryRevision
            ) {
                throw new RuntimeException('The pinned Space memory revision is stale.');
            }

            if ($supersedesMemoryId !== null) {
                $superseded = $database->query(<<<'SQL'
                    SELECT id
                    FROM space_memory_versions
                    WHERE id = ? AND space_id = ? AND status = 'active'
                    FOR UPDATE
                    SQL, [$supersedesMemoryId, $spaceId])->fetch();

                if (!is_array($superseded)) {
                    throw new RuntimeException('The active Space memory to supersede was not found.');
                }
            }

            $revision = (int) $spaceRow['memory_revision'] + 1;
            $id       = SpaceRecordId::new();
            $database->execute(<<<'SQL'
                INSERT INTO space_memory_versions (
                    id, space_id, revision, participant_key, participant_label,
                    memory, quote, context, status, idempotency_key,
                    supersedes_memory_id, provenance_json, confidence_permille,
                    created_at, source_updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                SQL, [
                $id,
                $spaceId,
                $revision,
                $participantKey,
                $participantLabel,
                $memory,
                $quote,
                $context,
                $status,
                $idempotencyKey,
                $supersedesMemoryId,
                $provenanceJson,
                $confidencePermille,
                $now,
                $now,
            ]);

            if ($supersedesMemoryId !== null) {
                $database->execute(<<<'SQL'
                    UPDATE space_memory_versions
                    SET status = 'superseded'
                    WHERE id = ? AND space_id = ?
                    SQL, [$supersedesMemoryId, $spaceId]);
            }

            $database->execute(<<<'SQL'
                UPDATE agent_spaces
                SET memory_revision = ?, updated_at = ?
                WHERE id = ?
                SQL, [$revision, $now, $spaceId]);

            return new SpaceMemoryVersion(
                id: $id,
                spaceId: $spaceId,
                revision: $revision,
                participantKey: $participantKey,
                participantLabel: $participantLabel,
                memory: $memory,
                quote: $quote,
                context: $context,
                status: $status,
                idempotencyKey: $idempotencyKey,
                supersedesMemoryId: $supersedesMemoryId,
                provenanceJson: $provenanceJson,
                confidencePermille: $confidencePermille,
                createdAt: $now,
                sourceUpdatedAt: $now,
            );
        });
    }

    private function findRaw(string $spaceId, string $memoryId): ?SpaceMemoryVersion
    {
        $row = $this->database->query(<<<'SQL'
            SELECT * FROM space_memory_versions WHERE id = ? AND space_id = ?
            SQL, [$memoryId, $spaceId])->fetch();

        return is_array($row) ? self::hydrate($row) : null;
    }

    private function findRawByIdempotencyKey(
        string $spaceId,
        string $idempotencyKey,
        DatabaseInterface $database,
    ): ?SpaceMemoryVersion {
        $row = $database->query(<<<'SQL'
            SELECT *
            FROM space_memory_versions
            WHERE space_id = ? AND idempotency_key = ?
            SQL, [$spaceId, $idempotencyKey])->fetch();

        return is_array($row) ? self::hydrate($row) : null;
    }
}
