<?php

declare(strict_types=1);

namespace Bot\Space\Persistence;

use Bot\Entity\Space\SpaceMemoryVersionRepository;
use Bot\Entity\Space\SpaceReleaseRepository;
use Bot\Entity\Space\SpaceSkillVersionRepository;
use Bot\Entity\SpaceMemoryVersion;
use Bot\Entity\SpacePromotionEvent;
use Bot\Entity\SpaceRelease;
use Bot\Entity\SpaceSkillVersion;
use Cycle\Database\DatabaseInterface;
use Cycle\ORM\ORMInterface;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Persistence facade used by Space routing, snapshot loading, and dream activities.
 */
final readonly class SpaceStore
{
    public function __construct(
        private ORMInterface $orm,
        private DatabaseInterface $database,
    ) {}

    public function resolve(SpaceBindingKey $key): ?string
    {
        $row = $this->database->query(<<<'SQL'
            SELECT space_id
            FROM space_bindings
            WHERE
                bot_instance_id = ?
                AND platform = ?
                AND external_conversation_id = ?
                AND external_thread_id = ?
            SQL, [
            $key->botInstanceId,
            $key->platform,
            $key->externalConversationId,
            $key->externalThreadId,
        ])->fetch();

        return is_array($row) ? (string) $row['space_id'] : null;
    }

    public function resolveOrCreate(
        SpaceBindingKey $key,
        SpaceReleaseSeed $initialRelease,
        ?int $now = null,
    ): SpaceActivationSnapshot {
        $resolved = $this->resolve($key);
        if ($resolved !== null) {
            return $this->activationSnapshot($resolved);
        }

        $now ??= time();
        $spaceId       = SpaceId::forBinding($key);
        $releaseDigest = $initialRelease->digest();
        $releaseId     = SpaceRecordId::forSeed('release:v2:' . $spaceId . ':1:' . $releaseDigest);
        $bindingId     = SpaceRecordId::forSeed('binding:v2:' . $key->canonical());

        $this->database->transaction(function (DatabaseInterface $database) use (
            $key,
            $initialRelease,
            $now,
            $spaceId,
            $releaseDigest,
            $releaseId,
            $bindingId,
        ): void {
            $database->execute(<<<'SQL'
                INSERT INTO agent_spaces (
                    id, status, active_release_id, release_generation, memory_revision,
                    dream_enabled, dream_time_zone, last_dream_at, created_at, updated_at
                ) VALUES (?, 'active', NULL, 0, 0, true, 'Asia/Yekaterinburg', NULL, ?, ?)
                ON CONFLICT (id) DO NOTHING
                SQL, [$spaceId, $now, $now]);
            $database->execute(<<<'SQL'
                INSERT INTO space_releases (
                    id, space_id, parent_release_id, source_proposal_id,
                    sequence, status, release_digest,
                    model, prompt, personality_json, manifest_json, capability_policy_json,
                    artifact_digest, evaluation_digest, created_by, created_at, activated_at
                ) VALUES (?, ?, NULL, NULL, 1, 'active', ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?)
                ON CONFLICT (space_id, sequence) DO NOTHING
                SQL, [
                $releaseId,
                $spaceId,
                $releaseDigest,
                $initialRelease->model,
                $initialRelease->prompt,
                $initialRelease->personalityJson,
                $initialRelease->manifestJson,
                $initialRelease->capabilityPolicyJson,
                $initialRelease->artifactDigest,
                $initialRelease->createdBy,
                $now,
                $now,
            ]);
            $database->execute(<<<'SQL'
                UPDATE agent_spaces AS space
                SET
                    active_release_id = release.id,
                    release_generation = 1,
                    updated_at = ?
                FROM space_releases AS release
                WHERE
                    space.id = ?
                    AND space.active_release_id IS NULL
                    AND release.space_id = space.id
                    AND release.sequence = 1
                SQL, [$now, $spaceId]);
            $database->execute(<<<'SQL'
                INSERT INTO space_bindings (
                    id, space_id, bot_instance_id, platform,
                    external_conversation_id, external_thread_id, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (
                    bot_instance_id, platform, external_conversation_id, external_thread_id
                ) DO NOTHING
                SQL, [
                $bindingId,
                $spaceId,
                $key->botInstanceId,
                $key->platform,
                $key->externalConversationId,
                $key->externalThreadId,
                $now,
            ]);
        });

        $resolved = $this->resolve($key);
        if ($resolved === null) {
            throw new RuntimeException('Space provisioning completed without a binding.');
        }

        return $this->activationSnapshot($resolved);
    }

    public function activationSnapshot(string $spaceId): SpaceActivationSnapshot
    {
        $space = $this->database->query(<<<'SQL'
            SELECT id, active_release_id, release_generation, memory_revision
            FROM agent_spaces
            WHERE id = ? AND status = 'active'
            SQL, [$spaceId])->fetch();

        if (!is_array($space)) {
            throw new RuntimeException(sprintf('Active Space "%s" was not found.', $spaceId));
        }

        if ($space['active_release_id'] === null) {
            throw new RuntimeException(sprintf('Space "%s" has no active release.', $spaceId));
        }

        return new SpaceActivationSnapshot(
            spaceId: (string) $space['id'],
            releaseId: (string) $space['active_release_id'],
            releaseGeneration: (int) $space['release_generation'],
            memoryRevision: (int) $space['memory_revision'],
        );
    }

    public function currentRelease(string $spaceId): SpaceRelease
    {
        $snapshot = $this->activationSnapshot($spaceId);

        /** @var SpaceReleaseRepository $repository */
        $repository = $this->orm->getRepository(SpaceRelease::class);

        return $repository->findForSpace($spaceId, $snapshot->releaseId)
            ?? throw new RuntimeException('The active Space release is missing.');
    }

    public function createCandidateRelease(
        string $spaceId,
        string $expectedParentReleaseId,
        string $proposalId,
        SpaceReleaseSeed $candidate,
        ?int $now = null,
    ): SpaceRelease {
        SpaceId::assert($spaceId);

        if (trim($proposalId) === '') {
            throw new RuntimeException('A candidate release requires a proposal ID.');
        }

        $now ??= time();
        $digest = $candidate->digest();

        return $this->database->transaction(function (DatabaseInterface $database) use (
            $spaceId,
            $expectedParentReleaseId,
            $proposalId,
            $candidate,
            $now,
            $digest,
        ): SpaceRelease {
            $space = $database->query(<<<'SQL'
                SELECT active_release_id
                FROM agent_spaces
                WHERE id = ? AND status = 'active'
                FOR UPDATE
                SQL, [$spaceId])->fetch();

            if (!is_array($space)) {
                throw new RuntimeException('Cannot create a candidate for a missing or disabled Space.');
            }

            $existing = $database->query(<<<'SQL'
                SELECT *
                FROM space_releases
                WHERE space_id = ? AND source_proposal_id = ?
                SQL, [$spaceId, $proposalId])->fetch();
            if (is_array($existing)) {
                if ((string) $existing['parent_release_id'] !== $expectedParentReleaseId
                    || (string) $existing['release_digest'] !== $digest
                    || (string) $existing['model'] !== $candidate->model
                    || (string) $existing['prompt'] !== $candidate->prompt
                    || (string) $existing['personality_json'] !== $candidate->personalityJson
                    || (string) $existing['manifest_json'] !== $candidate->manifestJson
                    || (string) $existing['capability_policy_json'] !== $candidate->capabilityPolicyJson
                    || $existing['artifact_digest'] !== $candidate->artifactDigest
                ) {
                    throw new RuntimeException('Proposal idempotency key already names a different immutable candidate.');
                }

                return self::hydrateRelease($existing);
            }

            if ((string) $space['active_release_id'] !== $expectedParentReleaseId) {
                throw new RuntimeException('The candidate baseline release is stale.');
            }

            $latestSequence = (int) $database->query(<<<'SQL'
                SELECT COALESCE(MAX(sequence), 0)
                FROM space_releases
                WHERE space_id = ?
                SQL, [$spaceId])->fetchColumn();
            $sequence  = $latestSequence + 1;
            $releaseId = SpaceRecordId::forSeed(
                'release:v2:' . $spaceId . ':' . $sequence . ':' . $proposalId . ':' . $digest,
            );

            $database->execute(<<<'SQL'
                INSERT INTO space_releases (
                    id, space_id, parent_release_id, source_proposal_id,
                    sequence, status, release_digest, model, prompt,
                    personality_json, manifest_json, capability_policy_json,
                    artifact_digest, evaluation_digest, created_by, created_at, activated_at
                ) VALUES (?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, NULL)
                SQL, [
                $releaseId,
                $spaceId,
                $expectedParentReleaseId,
                $proposalId,
                $sequence,
                $digest,
                $candidate->model,
                $candidate->prompt,
                $candidate->personalityJson,
                $candidate->manifestJson,
                $candidate->capabilityPolicyJson,
                $candidate->artifactDigest,
                $candidate->createdBy,
                $now,
            ]);

            return new SpaceRelease(
                id: $releaseId,
                spaceId: $spaceId,
                parentReleaseId: $expectedParentReleaseId,
                sourceProposalId: $proposalId,
                sequence: $sequence,
                status: SpaceRelease::STATUS_DRAFT,
                releaseDigest: $digest,
                model: $candidate->model,
                prompt: $candidate->prompt,
                personalityJson: $candidate->personalityJson,
                manifestJson: $candidate->manifestJson,
                capabilityPolicyJson: $candidate->capabilityPolicyJson,
                artifactDigest: $candidate->artifactDigest,
                createdBy: $candidate->createdBy,
                createdAt: $now,
            );
        });
    }

    /**
     * Materialize the complete immutable skill set for a candidate. Skills not
     * named in the patch are copied from the parent; named entries replace or
     * disable the prior version.
     *
     * @param list<array{name: string, description: string, body: string, enabled?: bool}> $skillPatch
     * @param string                                                                       $spaceId
     * @param string                                                                       $parentReleaseId
     * @param string                                                                       $candidateReleaseId
     * @param ?int                                                                         $now
     */
    public function materializeCandidateSkills(
        string $spaceId,
        string $parentReleaseId,
        string $candidateReleaseId,
        array $skillPatch,
        ?int $now = null,
    ): void {
        $now ??= time();
        $patchByName = [];
        foreach ($skillPatch as $skill) {
            $name = trim((string) ($skill['name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('A candidate skill requires a name.');
            }
            if (isset($patchByName[$name])) {
                throw new RuntimeException('A candidate skill patch cannot repeat a name.');
            }
            $patchByName[$name] = $skill;
        }

        $this->database->transaction(function (DatabaseInterface $database) use (
            $spaceId,
            $parentReleaseId,
            $candidateReleaseId,
            $patchByName,
            $now,
        ): void {
            $candidate = $database->query(<<<'SQL'
                SELECT id, parent_release_id, status, manifest_json
                FROM space_releases
                WHERE id = ? AND space_id = ?
                FOR UPDATE
                SQL, [$candidateReleaseId, $spaceId])->fetch();
            $parent = $database->query(<<<'SQL'
                SELECT id FROM space_releases
                WHERE id = ? AND space_id = ?
                FOR UPDATE
                SQL, [$parentReleaseId, $spaceId])->fetch();
            if (!is_array($candidate)
                || !is_array($parent)
                || (string) $candidate['parent_release_id'] !== $parentReleaseId
                || !in_array($candidate['status'], [
                    SpaceRelease::STATUS_DRAFT,
                    SpaceRelease::STATUS_BUILDING,
                ], true)
            ) {
                throw new RuntimeException('Candidate skills require a draft direct child in the same Space.');
            }
            $parentSkills = $database->query(<<<'SQL'
                SELECT * FROM space_skill_versions
                WHERE release_id = ? AND space_id = ?
                ORDER BY name ASC
                SQL, [$parentReleaseId, $spaceId])->fetchAll();
            $existingVersions = [];
            foreach ($database->query(<<<'SQL'
                SELECT name, MAX(version) AS version
                FROM space_skill_versions
                WHERE space_id = ?
                GROUP BY name
                SQL, [$spaceId])->fetchAll() as $row) {
                if (is_array($row)) {
                    $existingVersions[(string) $row['name']] = (int) $row['version'];
                }
            }

            $materialized = [];
            foreach ($parentSkills as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name  = (string) $row['name'];
                $patch = $patchByName[$name] ?? null;
                unset($patchByName[$name]);
                $materialized[$name] = $patch === null
                    ? [
                        'description'  => (string) $row['description'],
                        'body'         => (string) $row['body'],
                        'enabled'      => self::databaseBoolean($row['enabled']),
                        'manifestJson' => (string) $row['manifest_json'],
                        'sourceDigest' => $row['source_digest'],
                    ]
                    : self::normalizedSkillPatch($patch);
            }
            foreach ($patchByName as $name => $patch) {
                $materialized[$name] = self::normalizedSkillPatch($patch);
            }

            ksort($materialized);
            if (count($materialized) > 20) {
                throw new RuntimeException('A Space release cannot contain more than 20 skills.');
            }
            $enabledBytes = 0;
            foreach ($materialized as $skill) {
                if ($skill['enabled']) {
                    $enabledBytes += strlen($skill['description']) + strlen($skill['body']);
                }
            }
            if ($enabledBytes > 50_000) {
                throw new RuntimeException('Enabled Space skills exceed the 50 KB release budget.');
            }
            self::assertMaterializedSkillsDigest((string) $candidate['manifest_json'], $materialized);

            $existingRows = $database->query(<<<'SQL'
                SELECT name, description, body, manifest_json, source_digest, enabled
                FROM space_skill_versions
                WHERE release_id = ? AND space_id = ?
                ORDER BY name ASC
                SQL, [$candidateReleaseId, $spaceId])->fetchAll();
            if ($existingRows !== []) {
                $existingMaterialized = [];
                foreach ($existingRows as $row) {
                    if (is_array($row)) {
                        $existingMaterialized[(string) $row['name']] = [
                            'description'  => (string) $row['description'],
                            'body'         => (string) $row['body'],
                            'enabled'      => self::databaseBoolean($row['enabled']),
                            'manifestJson' => (string) $row['manifest_json'],
                            'sourceDigest' => $row['source_digest'],
                        ];
                    }
                }
                if ($existingMaterialized !== $materialized) {
                    throw new RuntimeException('Candidate skills were only partially or differently materialized.');
                }

                return;
            }

            foreach ($materialized as $name => $skill) {
                $version = ($existingVersions[$name] ?? 0) + 1;
                $database->execute(<<<'SQL'
                    INSERT INTO space_skill_versions (
                        id, space_id, release_id, name, version, description, body,
                        manifest_json, source_digest, enabled, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    SQL, [
                    SpaceRecordId::forSeed(
                        'skill:v2:' . $spaceId . ':' . $candidateReleaseId . ':' . $name,
                    ),
                    $spaceId,
                    $candidateReleaseId,
                    $name,
                    $version,
                    $skill['description'],
                    $skill['body'],
                    $skill['manifestJson'],
                    $skill['sourceDigest'],
                    $skill['enabled'],
                    $now,
                ]);
            }
        });
    }

    /**
     * @param string $spaceId
     * @param bool   $enabledOnly
     *
     * @return array<SpaceSkillVersion>
     */
    public function currentSkills(string $spaceId, bool $enabledOnly = true): array
    {
        $release = $this->currentRelease($spaceId);

        /** @var SpaceSkillVersionRepository $repository */
        $repository = $this->orm->getRepository(SpaceSkillVersion::class);

        return $repository->findForRelease($release->id, $enabledOnly);
    }

    public function createSkillVersion(
        string $spaceId,
        string $releaseId,
        string $name,
        string $description,
        string $body,
        bool $enabled = true,
        string $manifestJson = '{}',
        ?string $sourceDigest = null,
        ?int $now = null,
    ): SpaceSkillVersion {
        SpaceId::assert($spaceId);
        $name = trim($name);
        if ($name === '' || trim($body) === '') {
            throw new InvalidArgumentException('Space skill name and body must not be empty.');
        }

        try {
            json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Space skill manifest must be valid JSON.', previous: $exception);
        }

        $sourceDigest ??= 'sha256:' . hash('sha256', $body);
        $now ??= time();

        return $this->database->transaction(function (DatabaseInterface $database) use (
            $spaceId,
            $releaseId,
            $name,
            $description,
            $body,
            $enabled,
            $manifestJson,
            $sourceDigest,
            $now,
        ): SpaceSkillVersion {
            $release = $database->query(<<<'SQL'
                SELECT status
                FROM space_releases
                WHERE id = ? AND space_id = ?
                FOR UPDATE
                SQL, [$releaseId, $spaceId])->fetch();

            if (!is_array($release) || !in_array($release['status'], [
                SpaceRelease::STATUS_DRAFT,
                SpaceRelease::STATUS_BUILDING,
            ], true)) {
                throw new RuntimeException('Skills can only be attached to a draft or building release.');
            }

            $existing = $database->query(<<<'SQL'
                SELECT *
                FROM space_skill_versions
                WHERE release_id = ? AND name = ?
                SQL, [$releaseId, $name])->fetch();
            if (is_array($existing)) {
                if (
                    (string) $existing['description'] !== $description
                    || (string) $existing['body'] !== $body
                    || self::databaseBoolean($existing['enabled']) !== $enabled
                    || (string) $existing['manifest_json'] !== $manifestJson
                    || (string) $existing['source_digest'] !== $sourceDigest
                ) {
                    throw new RuntimeException('A different immutable skill already exists in this release.');
                }

                return self::hydrateSkill($existing);
            }

            $latestVersion = (int) $database->query(<<<'SQL'
                SELECT COALESCE(MAX(version), 0)
                FROM space_skill_versions
                WHERE space_id = ? AND name = ?
                SQL, [$spaceId, $name])->fetchColumn();
            $version = $latestVersion + 1;
            $id      = SpaceRecordId::forSeed(
                'skill:v2:' . $spaceId . ':' . $name . ':' . $version . ':' . $sourceDigest,
            );

            $database->execute(<<<'SQL'
                INSERT INTO space_skill_versions (
                    id, space_id, release_id, name, version, description, body,
                    manifest_json, source_digest, enabled, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                SQL, [
                $id,
                $spaceId,
                $releaseId,
                $name,
                $version,
                $description,
                $body,
                $manifestJson,
                $sourceDigest,
                $enabled,
                $now,
            ]);

            return new SpaceSkillVersion(
                id: $id,
                spaceId: $spaceId,
                releaseId: $releaseId,
                name: $name,
                version: $version,
                description: $description,
                body: $body,
                manifestJson: $manifestJson,
                sourceDigest: $sourceDigest,
                enabled: $enabled,
                createdAt: $now,
            );
        });
    }

    /**
     * @param string  $spaceId
     * @param ?string $participantKey
     *
     * @return array<SpaceMemoryVersion>
     */
    public function activeMemories(string $spaceId, ?string $participantKey = null): array
    {
        /** @var SpaceMemoryVersionRepository $repository */
        $repository = $this->orm->getRepository(SpaceMemoryVersion::class);

        return $repository->findActive($spaceId, $participantKey);
    }

    public function compareAndSwapRelease(
        string $spaceId,
        string $expectedReleaseId,
        int $expectedGeneration,
        string $targetReleaseId,
        string $actor,
        ?string $proposalId = null,
        string $policyDecisionJson = '{}',
        bool $rollback = false,
    ): SpaceActivationResult {
        return (new SpaceReleaseActivator($this->database))->compareAndSwap(
            spaceId: $spaceId,
            expectedReleaseId: $expectedReleaseId,
            expectedGeneration: $expectedGeneration,
            targetReleaseId: $targetReleaseId,
            action: $rollback
                ? SpacePromotionEvent::ACTION_ROLLBACK
                : SpacePromotionEvent::ACTION_PROMOTE,
            actor: $actor,
            proposalId: $proposalId,
            policyDecisionJson: $policyDecisionJson,
        );
    }

    /**
     * @param array{name?: mixed, description?: mixed, body?: mixed, enabled?: mixed} $patch
     *
     * @return array{description: string, body: string, enabled: bool, manifestJson: string, sourceDigest: string}
     */
    private static function normalizedSkillPatch(array $patch): array
    {
        $description = trim((string) ($patch['description'] ?? ''));
        $body        = trim((string) ($patch['body'] ?? ''));
        if ($description === '' || $body === '') {
            throw new RuntimeException('A candidate skill needs a description and body.');
        }

        return [
            'description'  => $description,
            'body'         => $body,
            'enabled'      => is_bool($patch['enabled'] ?? null) ? $patch['enabled'] : true,
            'manifestJson' => json_encode(
                ['source' => 'nightly-dream'],
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES,
            ),
            'sourceDigest' => 'sha256:' . hash('sha256', $body),
        ];
    }

    /**
     * @param array<string, array{
     *     description: string,
     *     body: string,
     *     enabled: bool,
     *     manifestJson: string,
     *     sourceDigest: null|string
     * }> $materialized
     * @param string $manifestJson
     */
    private static function assertMaterializedSkillsDigest(string $manifestJson, array $materialized): void
    {
        try {
            $manifest = json_decode($manifestJson, true, flags: \JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Candidate release manifest is invalid.', previous: $exception);
        }
        $expected = is_array($manifest) ? ($manifest['skillsDigest'] ?? null) : null;
        if (!is_string($expected) || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $expected) !== 1) {
            throw new RuntimeException('Candidate release has no valid skills digest.');
        }

        $content = [];
        foreach ($materialized as $name => $skill) {
            $content[] = [
                'name'        => $name,
                'description' => $skill['description'],
                'body'        => $skill['body'],
                'enabled'     => $skill['enabled'],
            ];
        }
        $actual = 'sha256:' . hash('sha256', json_encode(
            $content,
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        ));
        if (!hash_equals($expected, $actual)) {
            throw new RuntimeException('Materialized candidate skills do not match the release manifest.');
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrateRelease(array $row): SpaceRelease
    {
        return new SpaceRelease(
            id: (string) $row['id'],
            spaceId: (string) $row['space_id'],
            parentReleaseId: $row['parent_release_id'] === null ? null : (string) $row['parent_release_id'],
            sourceProposalId: $row['source_proposal_id'] === null ? null : (string) $row['source_proposal_id'],
            sequence: (int) $row['sequence'],
            status: (string) $row['status'],
            releaseDigest: (string) $row['release_digest'],
            model: (string) $row['model'],
            prompt: (string) $row['prompt'],
            personalityJson: (string) $row['personality_json'],
            manifestJson: (string) $row['manifest_json'],
            capabilityPolicyJson: (string) $row['capability_policy_json'],
            artifactDigest: $row['artifact_digest'] === null ? null : (string) $row['artifact_digest'],
            evaluationDigest: $row['evaluation_digest'] === null
                ? null
                : (string) $row['evaluation_digest'],
            createdBy: (string) $row['created_by'],
            createdAt: (int) $row['created_at'],
            activatedAt: $row['activated_at'] === null ? null : (int) $row['activated_at'],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrateSkill(array $row): SpaceSkillVersion
    {
        return new SpaceSkillVersion(
            id: (string) $row['id'],
            spaceId: (string) $row['space_id'],
            releaseId: (string) $row['release_id'],
            name: (string) $row['name'],
            version: (int) $row['version'],
            description: (string) $row['description'],
            body: (string) $row['body'],
            manifestJson: (string) $row['manifest_json'],
            sourceDigest: $row['source_digest'] === null ? null : (string) $row['source_digest'],
            enabled: self::databaseBoolean($row['enabled']),
            createdAt: (int) $row['created_at'],
        );
    }

    private static function databaseBoolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
