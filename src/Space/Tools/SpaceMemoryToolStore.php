<?php

declare(strict_types=1);

namespace Bot\Space\Tools;

use Bot\Entity\SpaceMemoryVersion;
use Bot\Space\Memory\SpaceMemoryContentPolicy;
use Bot\Space\Persistence\SpaceMemoryStore;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final readonly class SpaceMemoryToolStore
{
    public function __construct(private SpaceMemoryStore $memories) {}

    public function save(
        string $spaceId,
        string $userIdentifier,
        string $memory,
        string $quote,
        string $context,
        string $idempotencyKey,
        SpaceMemoryMutationAuthority $authority,
    ): string {
        self::assertSafeContent($memory, $quote, $context);
        $participant    = self::participant($userIdentifier);
        $idempotencyKey = self::idempotencyKey($idempotencyKey);
        $existing       = $this->memories->byIdempotencyKey($spaceId, $idempotencyKey);
        $provenance     = $authority->authorizeEvidence(
            $spaceId,
            mb_strtolower($participant),
            $quote,
            self::persistedAuthority($existing, 'agent-save', $idempotencyKey),
        );
        $record = $this->memories->append(
            spaceId: $spaceId,
            participantKey: mb_strtolower($participant),
            participantLabel: $participant,
            memory: trim($memory),
            quote: trim($quote),
            context: trim($context),
            provenanceJson: self::provenance(
                $idempotencyKey,
                'agent-save',
                ['authority' => $provenance],
            ),
            idempotencyKey: $idempotencyKey,
        );

        return sprintf('Memory saved for %s (%s): %s', $participant, $record->id, $record->memory);
    }

    public function recall(
        string $spaceId,
        ?string $userIdentifier = null,
        ?string $query = null,
        int $limit = 10,
        ?int $atRevision = null,
    ): string {
        $participant = $userIdentifier === null ? null : self::participant($userIdentifier);
        $records     = $this->memories->recall(
            $spaceId,
            $participant === null ? null : mb_strtolower($participant),
            100,
            $atRevision,
        );
        $records = self::matching($records, $query);
        $records = array_slice($records, 0, max(1, min($limit, 20)));
        if ($records === []) {
            return 'No matching Space memories found.';
        }

        $lines = ['Relevant Space memories:'];
        foreach ($records as $record) {
            $lines[] = sprintf(
                '- %s | %s | memory: %s | quote: %s | context: %s',
                $record->id,
                $record->participantLabel,
                $record->memory,
                $record->quote,
                $record->context,
            );
        }

        return implode("\n", $lines);
    }

    public function update(
        string $spaceId,
        string $memory,
        string $quote,
        string $context,
        ?string $memoryId,
        ?string $userIdentifier,
        ?string $currentMemory,
        ?string $query,
        string $idempotencyKey,
        SpaceMemoryMutationAuthority $authority,
        ?int $atRevision = null,
    ): string {
        self::assertSafeContent($memory, $quote, $context);
        $idempotencyKey = self::idempotencyKey($idempotencyKey);
        $existing       = $this->memories->byIdempotencyKey($spaceId, $idempotencyKey);
        if ($existing !== null) {
            $target = $this->replayTarget(
                spaceId: $spaceId,
                existing: $existing,
                expectedStatus: SpaceMemoryVersion::STATUS_ACTIVE,
                memoryId: $memoryId,
                userIdentifier: $userIdentifier,
                query: $currentMemory ?? $query,
                atRevision: $atRevision,
            );
        } else {
            $target = $this->oneTarget(
                $spaceId,
                $memoryId,
                $userIdentifier,
                $currentMemory ?? $query,
                $atRevision,
            );
        }
        $provenanceJson = self::provenance(
            $idempotencyKey,
            'agent-update',
            ['authority' => $authority->authorizeEvidence(
                $spaceId,
                $target->participantKey,
                $quote,
                self::persistedAuthority($existing, 'agent-update', $idempotencyKey),
            )],
        );
        if ($existing !== null) {
            self::assertReplayPayload(
                existing: $existing,
                memory: trim($memory),
                quote: trim($quote),
                context: trim($context),
                provenanceJson: $provenanceJson,
            );
        }
        $record = $this->memories->update(
            spaceId: $spaceId,
            memoryId: $target->id,
            participantKey: $target->participantKey,
            participantLabel: $target->participantLabel,
            memory: trim($memory),
            quote: trim($quote),
            context: trim($context),
            provenanceJson: $provenanceJson,
            idempotencyKey: $idempotencyKey,
            expectedMemoryRevision: $atRevision,
        );

        return sprintf('Memory updated for %s (%s): %s', $record->participantLabel, $record->id, $record->memory);
    }

    public function forget(
        string $spaceId,
        ?string $memoryId,
        ?string $userIdentifier,
        ?string $query,
        bool $forgetAllForParticipant,
        string $evidenceQuote,
        string $idempotencyKey,
        SpaceMemoryMutationAuthority $authority,
        ?int $atRevision = null,
    ): string {
        $participant    = $userIdentifier === null ? null : self::participant($userIdentifier);
        $idempotencyKey = self::idempotencyKey($idempotencyKey);
        if ($forgetAllForParticipant) {
            if ($participant === null) {
                return 'Memory not forgotten: participant reference is required.';
            }

            return $this->forgetAll(
                $spaceId,
                $participant,
                $query,
                $evidenceQuote,
                $idempotencyKey,
                $authority,
                $atRevision,
            );
        }

        $existing = $this->memories->byIdempotencyKey($spaceId, $idempotencyKey);
        if ($existing !== null) {
            $target = $this->replayTarget(
                spaceId: $spaceId,
                existing: $existing,
                expectedStatus: SpaceMemoryVersion::STATUS_FORGOTTEN,
                memoryId: $memoryId,
                userIdentifier: $participant,
                query: $query,
                atRevision: $atRevision,
            );
        } else {
            $target = $this->oneTarget($spaceId, $memoryId, $participant, $query, $atRevision);
        }
        $provenance = $authority->authorizeEvidence(
            $spaceId,
            $target->participantKey,
            $evidenceQuote,
            self::persistedAuthority($existing, 'agent-forget', $idempotencyKey),
        );
        $record = $this->memories->forget(
            $spaceId,
            $target->id,
            self::provenance(
                $idempotencyKey,
                'agent-forget',
                ['authority' => $provenance],
            ),
            $idempotencyKey,
            expectedMemoryRevision: $atRevision,
        );

        return sprintf(
            'Memory forgotten for %s (%s): %s',
            $record->participantLabel,
            $record->supersedesMemoryId ?? $record->id,
            $record->memory,
        );
    }

    private static function assertReplayPayload(
        SpaceMemoryVersion $existing,
        string $memory,
        string $quote,
        string $context,
        string $provenanceJson,
    ): void {
        if ($existing->memory !== $memory
            || $existing->quote !== $quote
            || $existing->context !== $context
            || $existing->provenanceJson !== $provenanceJson
        ) {
            throw new RuntimeException('Memory idempotency key already names a different mutation.');
        }
    }

    /**
     * @param ?SpaceMemoryVersion $existing
     * @param string              $operation
     * @param string              $idempotencyKey
     *
     * @return array<string, mixed>|null
     */
    private static function persistedAuthority(
        ?SpaceMemoryVersion $existing,
        string $operation,
        string $idempotencyKey,
    ): ?array {
        if ($existing === null) {
            return null;
        }

        try {
            $provenance = json_decode($existing->provenanceJson, true, flags: \JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored memory idempotency provenance is invalid.', previous: $exception);
        }
        $authority = is_array($provenance) ? ($provenance['authority'] ?? null) : null;
        if (
            !is_array($provenance)
            || ($provenance['source'] ?? null) !== $operation
            || ($provenance['idempotencyKey'] ?? null) !== $idempotencyKey
            || !is_array($authority)
        ) {
            throw new RuntimeException('Memory idempotency key already names a different mutation.');
        }

        return $authority;
    }

    /**
     * @param array<SpaceMemoryVersion> $records
     * @param ?string                   $query
     *
     * @return array<SpaceMemoryVersion>
     */
    private static function matching(array $records, ?string $query): array
    {
        $query = mb_strtolower(trim($query ?? ''));
        if ($query === '') {
            return $records;
        }
        $tokens = preg_split('/\s+/', $query) ?: [];

        return array_values(array_filter($records, static function (SpaceMemoryVersion $record) use ($tokens): bool {
            $haystack = mb_strtolower(implode("\n", [
                $record->participantKey,
                $record->participantLabel,
                $record->memory,
                $record->quote,
                $record->context,
            ]));
            foreach ($tokens as $token) {
                if ($token !== '' && !str_contains($haystack, $token)) {
                    return false;
                }
            }

            return true;
        }));
    }

    private static function matches(SpaceMemoryVersion $record, ?string $query): bool
    {
        return self::matching([$record], $query) !== [];
    }

    private static function idempotencyKey(string $idempotencyKey): string
    {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('Memory idempotency key must not be empty.');
        }

        return $idempotencyKey;
    }

    private static function participant(string $participant): string
    {
        $participant = trim($participant);
        if (preg_match('/^(?:telegram_user:[1-9]\d*|telegram_chat:-?[1-9]\d*)$/', $participant) !== 1) {
            throw new InvalidArgumentException('Use an immutable telegram_user:<id> or telegram_chat:<id> reference.');
        }

        return $participant;
    }

    /**
     * @param array<string, mixed> $details
     * @param string               $idempotencyKey
     * @param string               $operation
     */
    private static function provenance(string $idempotencyKey, string $operation, array $details = []): string
    {
        return json_encode([
            'source'         => $operation,
            'idempotencyKey' => $idempotencyKey,
            ...$details,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<SpaceMemoryVersion> $existing
     * @param string                    $targetKeyPrefix
     *
     * @return array{targetIds: list<string>, authority: array<string, mixed>}
     */
    private static function forgetAllReplay(array $existing, string $targetKeyPrefix): array
    {
        $targetIds = null;
        $authority = null;
        foreach ($existing as $record) {
            if ($record->status !== SpaceMemoryVersion::STATUS_FORGOTTEN
                || $record->supersedesMemoryId === null
                || $record->idempotencyKey !== $targetKeyPrefix . $record->supersedesMemoryId
            ) {
                throw new RuntimeException('Memory idempotency prefix already names a different operation.');
            }

            try {
                $provenance = json_decode($record->provenanceJson, true, flags: \JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Stored memory idempotency provenance is invalid.', previous: $exception);
            }
            $candidateIds       = $provenance['targetMemoryIds'] ?? null;
            $candidateAuthority = $provenance['authority'] ?? null;
            if (($provenance['source'] ?? null) !== 'agent-forget-all'
                || ($provenance['idempotencyKey'] ?? null) !== $record->idempotencyKey
                || !is_array($candidateIds)
                || !array_is_list($candidateIds)
                || $candidateIds === []
                || array_filter($candidateIds, 'is_string') !== $candidateIds
                || array_filter($candidateIds, static fn (string $id): bool => trim($id) !== '') !== $candidateIds
                || count(array_unique($candidateIds)) !== count($candidateIds)
                || !in_array($record->supersedesMemoryId, $candidateIds, true)
                || !is_array($candidateAuthority)
            ) {
                throw new RuntimeException('Stored forget-all idempotency provenance is inconsistent.');
            }
            $candidateIds = array_values($candidateIds);
            if ($targetIds !== null && $targetIds !== $candidateIds) {
                throw new RuntimeException('Forget-all idempotency records disagree about their target set.');
            }
            if ($authority !== null && $authority !== $candidateAuthority) {
                throw new RuntimeException('Forget-all idempotency records disagree about their authority.');
            }
            $targetIds = $candidateIds;
            $authority = $candidateAuthority;
        }

        if ($targetIds === null || $authority === null) {
            throw new RuntimeException('Forget-all idempotency records are incomplete.');
        }

        return ['targetIds' => $targetIds, 'authority' => $authority];
    }

    private static function assertSafeContent(string ...$values): void
    {
        $violations = SpaceMemoryContentPolicy::violations(...$values);
        if ($violations !== []) {
            throw new InvalidArgumentException(sprintf(
                'Space memory contains disallowed private data: %s.',
                implode(', ', $violations),
            ));
        }
    }

    private function replayTarget(
        string $spaceId,
        SpaceMemoryVersion $existing,
        string $expectedStatus,
        ?string $memoryId,
        ?string $userIdentifier,
        ?string $query,
        ?int $atRevision,
    ): SpaceMemoryVersion {
        $existingOriginalStatus = $existing->status === SpaceMemoryVersion::STATUS_SUPERSEDED
            ? SpaceMemoryVersion::STATUS_ACTIVE
            : $existing->status;
        if ($existingOriginalStatus !== $expectedStatus || $existing->supersedesMemoryId === null) {
            throw new RuntimeException('Memory idempotency key already names a different operation.');
        }
        if ($memoryId !== null && $memoryId !== $existing->supersedesMemoryId) {
            throw new RuntimeException('Memory idempotency key already names a different target.');
        }

        $target = $this->oneTarget(
            $spaceId,
            $memoryId ?? $existing->supersedesMemoryId,
            $userIdentifier,
            $query,
            $atRevision,
        );
        if ($target->id !== $existing->supersedesMemoryId) {
            throw new RuntimeException('Memory idempotency key already names a different target.');
        }

        return $target;
    }

    private function oneTarget(
        string $spaceId,
        ?string $memoryId,
        ?string $userIdentifier,
        ?string $query,
        ?int $atRevision,
    ): SpaceMemoryVersion {
        $participant = $userIdentifier === null ? null : self::participant($userIdentifier);
        $records     = $this->memories->recall(
            $spaceId,
            $participant === null ? null : mb_strtolower($participant),
            100,
            $atRevision,
        );
        if ($memoryId !== null) {
            $records = array_values(array_filter(
                $records,
                static fn (SpaceMemoryVersion $record): bool => $record->id === $memoryId,
            ));
        } else {
            $records = self::matching($records, $query);
        }
        if (count($records) !== 1) {
            throw new RuntimeException(sprintf(
                'Memory selection must match exactly one active record; matched %d.',
                count($records),
            ));
        }

        return $records[0];
    }

    private function forgetAll(
        string $spaceId,
        string $participant,
        ?string $query,
        string $evidenceQuote,
        string $idempotencyKey,
        SpaceMemoryMutationAuthority $authority,
        ?int $atRevision,
    ): string {
        $targetKeyPrefix = $idempotencyKey . ':';
        $existing        = $this->memories->byIdempotencyPrefix($spaceId, $targetKeyPrefix);
        $targets         = self::matching(
            $this->memories->recall(
                $spaceId,
                mb_strtolower($participant),
                100,
                $atRevision,
            ),
            $query,
        );
        $targetsById = [];
        foreach ($targets as $target) {
            $targetsById[$target->id] = $target;
        }
        if ($existing === []) {
            $targetIds = array_map(
                static fn (SpaceMemoryVersion $target): string => $target->id,
                $targets,
            );
            $persistedAuthority = null;
        } else {
            $replay             = self::forgetAllReplay($existing, $targetKeyPrefix);
            $targetIds          = $replay['targetIds'];
            $persistedAuthority = $replay['authority'];
        }
        $authorityProvenance = $authority->authorizeEvidence(
            $spaceId,
            mb_strtolower($participant),
            $evidenceQuote,
            $persistedAuthority,
        );

        $requests = [];
        foreach ($targetIds as $targetId) {
            $target = $targetsById[$targetId]
                ?? throw new RuntimeException('A forget-all Space memory target is absent from the pinned revision.');
            if ($target->participantKey !== mb_strtolower($participant) || !self::matches($target, $query)) {
                throw new RuntimeException('Forget-all idempotency key already names a different target selection.');
            }
            $targetKey  = $targetKeyPrefix . $targetId;
            $requests[] = [
                'memoryId'       => $targetId,
                'provenanceJson' => self::provenance(
                    $targetKey,
                    'agent-forget-all',
                    [
                        'targetMemoryIds' => $targetIds,
                        'authority'       => $authorityProvenance,
                    ],
                ),
                'idempotencyKey' => $targetKey,
            ];
        }
        if ($requests !== []) {
            if ($atRevision === null) {
                throw new RuntimeException('Atomic forget-all requires a pinned Space memory revision.');
            }
            $this->memories->forgetMany(
                $spaceId,
                $requests,
                $atRevision,
            );
        }

        return sprintf('%d memories forgotten for %s.', count($targetIds), $participant);
    }
}
