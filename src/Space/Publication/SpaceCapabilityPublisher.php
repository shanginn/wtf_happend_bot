<?php

declare(strict_types=1);

namespace Bot\Space\Publication;

use Bot\Space\Persistence\SpaceId;
use Bot\Space\Persistence\SpaceRecordId;
use Bot\Space\Persistence\SpaceReleaseSeed;
use Bot\Space\Persistence\SqlBoolean;
use Bot\Space\Runtime\SpaceCommandBinding;
use Bot\Space\Runtime\SpaceRuntimeSnapshotLoaderActivity;
use Bot\Space\Runtime\SpaceRuntimeSnapshotPayloadTooLarge;
use Bot\Space\Tools\SpaceToolCatalog;
use Cycle\Database\DatabaseInterface;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Publishes one explicitly authorized capability as a new immutable release. */
final readonly class SpaceCapabilityPublisher
{
    private const string CREATOR              = 'live-space-capability-v1';
    private const int MAX_SKILLS              = 20;
    private const int MAX_ENABLED_SKILL_BYTES = 50_000;
    private const int MAX_COMMANDS            = 20;
    private const int MAX_COMMAND_BYTES       = 50_000;
    private const array RESERVED_COMMANDS     = ['clear', 'pause', 'resume'];

    public function __construct(private DatabaseInterface $database) {}

    /**
     * Returns authority committed by an earlier successful invocation. The host
     * still matches it to the exact current trusted update evidence before reuse.
     *
     * @param string $spaceId
     * @param string $terminalScopeId
     * @param string $invocationKey
     *
     * @return array<string, mixed>|null
     */
    public function persistedAuthority(
        string $spaceId,
        string $terminalScopeId,
        string $invocationKey,
    ): ?array {
        $publicationId = self::publicationIdFor($spaceId, $terminalScopeId, $invocationKey);
        $eventId       = SpaceRecordId::forSeed('promotion:' . $publicationId);
        $persisted     = self::persistedPublication(
            $this->database,
            $spaceId,
            $eventId,
            $publicationId,
        );
        if ($persisted === null) {
            return null;
        }

        return $persisted['authorizationProvenance'];
    }

    public function publish(
        SpaceCapabilityPublicationInput $input,
        ?int $now = null,
    ): SpaceCapabilityPublicationResult {
        $now ??= time();
        $requestJson    = self::canonicalJson($input->payload());
        $provenanceJson = self::canonicalJson($input->authorizationProvenance);
        $requestDigest  = self::digest($requestJson);
        $publicationId  = self::publicationId($input);
        $eventId        = SpaceRecordId::forSeed('promotion:' . $publicationId);

        return $this->database->transaction(function (DatabaseInterface $database) use (
            $input,
            $now,
            $requestJson,
            $provenanceJson,
            $requestDigest,
            $publicationId,
            $eventId,
        ): SpaceCapabilityPublicationResult {
            $persisted = self::persistedResult(
                $database,
                $input,
                $eventId,
                $requestJson,
                $provenanceJson,
                $requestDigest,
            );
            if ($persisted !== null) {
                return $persisted;
            }

            $snapshot = $database->query(<<<'SQL'
                SELECT id, space_id, batch_id, release_id, release_generation,
                       memory_revision, payload_json
                FROM space_runtime_snapshots
                WHERE id = ? AND space_id = ?
                SQL, [$input->runtimeSnapshotId, $input->spaceId])->fetch();
            if (!is_array($snapshot)) {
                throw new SpaceCapabilityPublicationRejected(
                    'The capability request has no exact persisted runtime snapshot.',
                );
            }
            if (($snapshot['id'] ?? null) !== $input->runtimeSnapshotId
                || ($snapshot['space_id'] ?? null) !== $input->spaceId
                || !is_string($snapshot['batch_id'] ?? null)
                || trim($snapshot['batch_id']) === ''
                || self::databaseInteger($snapshot['release_generation'] ?? null) < 0
                || self::databaseInteger($snapshot['memory_revision'] ?? null) < 0
            ) {
                throw new RuntimeException('The persisted runtime snapshot row is inconsistent.');
            }
            if (($input->authorizationProvenance['spaceId'] ?? null) !== $input->spaceId
                || ($input->authorizationProvenance['batchId'] ?? null) !== (string) $snapshot['batch_id']
            ) {
                throw new RuntimeException('The capability authority belongs to another Space request batch.');
            }
            $snapshotPayload = self::objectJson((string) $snapshot['payload_json'], 'runtime snapshot payload');
            if (($snapshotPayload['snapshotId'] ?? null) !== $input->runtimeSnapshotId
                || ($snapshotPayload['spaceId'] ?? null) !== $input->spaceId
                || ($snapshotPayload['releaseId'] ?? null) !== (string) $snapshot['release_id']
            ) {
                throw new RuntimeException('The persisted runtime snapshot identity is inconsistent.');
            }

            $space = $database->query(<<<'SQL'
                SELECT active_release_id, release_generation, memory_revision
                FROM agent_spaces
                WHERE id = ? AND status = 'active'
                FOR UPDATE
                SQL, [$input->spaceId])->fetch();
            if (!is_array($space)) {
                throw new SpaceCapabilityPublicationRejected(
                    'The capability request belongs to a missing or disabled Space.',
                );
            }
            $persisted = self::persistedResult(
                $database,
                $input,
                $eventId,
                $requestJson,
                $provenanceJson,
                $requestDigest,
            );
            if ($persisted !== null) {
                return $persisted;
            }

            $sourceReleaseId  = (string) $snapshot['release_id'];
            $sourceGeneration = self::databaseInteger($snapshot['release_generation']);
            if ((string) $space['active_release_id'] !== $sourceReleaseId
                || self::databaseInteger($space['release_generation']) !== $sourceGeneration
            ) {
                throw new SpaceCapabilityPublicationRejected(
                    'The pinned Space release is stale; publish from a new request batch.',
                );
            }

            $source = $database->query(<<<'SQL'
                SELECT *
                FROM space_releases
                WHERE id = ? AND space_id = ? AND status = 'active'
                FOR UPDATE
                SQL, [$sourceReleaseId, $input->spaceId])->fetch();
            if (!is_array($source)) {
                throw new RuntimeException('The pinned active Space release is missing.');
            }
            self::assertSourceRelease($source, $snapshot, $snapshotPayload);

            $skillRows = $database->query(<<<'SQL'
                SELECT name, description, body, manifest_json, source_digest, enabled
                FROM space_skill_versions
                WHERE release_id = ? AND space_id = ?
                ORDER BY name ASC
                FOR SHARE
                SQL, [$sourceReleaseId, $input->spaceId])->fetchAll();
            $skills   = self::skills($skillRows);
            $manifest = self::objectJson((string) $source['manifest_json'], 'source manifest');

            if ($input->kind === SpaceCapabilityPublicationInput::KIND_SKILL) {
                $skills[$input->name] = [
                    'name'          => $input->name,
                    'description'   => trim($input->description),
                    'body'          => trim($input->instructions),
                    'enabled'       => $input->enabled,
                    'manifest_json' => self::canonicalJson([
                        'source'        => self::CREATOR,
                        'publicationId' => $publicationId,
                        'requestDigest' => $requestDigest,
                    ]),
                    'source_digest' => self::digest(trim($input->instructions)),
                ];
            } else {
                self::putCommand($manifest, $input);
            }

            ksort($skills, \SORT_STRING);
            self::assertSkillBudget($skills);
            $skillContent = array_map(
                static fn (array $skill): array => [
                    'name'        => $skill['name'],
                    'description' => $skill['description'],
                    'body'        => $skill['body'],
                    'enabled'     => $skill['enabled'],
                ],
                array_values($skills),
            );
            $manifest['skillsDigest'] = 'sha256:' . hash('sha256', json_encode(
                $skillContent,
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
            ));
            $manifestJson = self::canonicalJson($manifest);
            $seed         = new SpaceReleaseSeed(
                model: (string) $source['model'],
                prompt: (string) $source['prompt'],
                personalityJson: (string) $source['personality_json'],
                manifestJson: $manifestJson,
                capabilityPolicyJson: (string) $source['capability_policy_json'],
                artifactDigest: $source['artifact_digest'] === null
                    ? null
                    : (string) $source['artifact_digest'],
                createdBy: self::CREATOR,
            );
            $releaseDigest = $seed->digest();
            $sequence      = (int) $database->query(
                'SELECT COALESCE(MAX(sequence), 0) FROM space_releases WHERE space_id = ?',
                [$input->spaceId],
            )->fetchColumn() + 1;
            $releaseId = SpaceRecordId::forSeed(implode(':', [
                'release:v2',
                $input->spaceId,
                $publicationId,
                $requestDigest,
                $releaseDigest,
            ]));
            self::assertReleaseEnvelope(
                sourceSnapshot: $snapshot,
                currentMemoryRevision: (string) $space['memory_revision'],
                seed: $seed,
                releaseId: $releaseId,
                releaseDigest: $releaseDigest,
                nextGeneration: $sourceGeneration + 1,
                skills: $skills,
            );

            if ($database->execute(<<<'SQL'
                INSERT INTO space_releases (
                    id, space_id, parent_release_id, source_proposal_id, sequence, status,
                    release_digest, model, prompt, personality_json, manifest_json,
                    capability_policy_json, artifact_digest, evaluation_digest,
                    created_by, created_at, activated_at
                ) VALUES (?, ?, ?, NULL, ?, 'building', ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, NULL)
                SQL, [
                $releaseId,
                $input->spaceId,
                $sourceReleaseId,
                $sequence,
                $releaseDigest,
                $seed->model,
                $seed->prompt,
                $seed->personalityJson,
                $seed->manifestJson,
                $seed->capabilityPolicyJson,
                $seed->artifactDigest,
                self::CREATOR,
                $now,
            ]) !== 1) {
                throw new RuntimeException('The immutable Space capability release was not created.');
            }
            self::insertSkills($database, $input->spaceId, $releaseId, $skills, $now);

            $nextGeneration = $sourceGeneration + 1;
            if ($database->execute(<<<'SQL'
                UPDATE agent_spaces
                SET active_release_id = ?, release_generation = ?, updated_at = ?
                WHERE id = ? AND active_release_id = ? AND release_generation = ?
                SQL, [
                $releaseId,
                $nextGeneration,
                $now,
                $input->spaceId,
                $sourceReleaseId,
                $sourceGeneration,
            ]) !== 1
                || $database->execute(
                    "UPDATE space_releases SET status = 'retired' WHERE id = ? AND space_id = ? AND status = 'active'",
                    [$sourceReleaseId, $input->spaceId],
                ) !== 1
                || $database->execute(
                    "UPDATE space_releases SET status = 'active', activated_at = ? WHERE id = ? AND space_id = ? AND status = 'building'",
                    [$now, $releaseId, $input->spaceId],
                ) !== 1
            ) {
                throw new RuntimeException('Space capability publication lost its active-release compare-and-swap fence.');
            }

            $policy = [
                'approved'                => true,
                'mode'                    => self::CREATOR,
                'publicationId'           => $publicationId,
                'runtimeSnapshotId'       => $input->runtimeSnapshotId,
                'requestDigest'           => $requestDigest,
                'request'                 => self::decodeObject($requestJson),
                'authorizationProvenance' => self::decodeObject($provenanceJson),
                'result'                  => [
                    'sourceReleaseId'   => $sourceReleaseId,
                    'releaseId'         => $releaseId,
                    'releaseGeneration' => $nextGeneration,
                    'kind'              => $input->kind,
                    'name'              => $input->name,
                ],
            ];
            if ($database->execute(<<<'SQL'
                INSERT INTO space_promotion_events (
                    id, space_id, proposal_id, from_release_id, to_release_id, action,
                    release_generation_before, release_generation_after, actor,
                    policy_decision_json, created_at
                ) VALUES (?, ?, NULL, ?, ?, 'promote', ?, ?, ?, ?, ?)
                SQL, [
                $eventId,
                $input->spaceId,
                $sourceReleaseId,
                $releaseId,
                $sourceGeneration,
                $nextGeneration,
                (string) $input->authorizationProvenance['actorParticipantKey'],
                self::canonicalJson($policy),
                $now,
            ]) !== 1) {
                throw new RuntimeException('Space capability publication event was not durably recorded.');
            }

            return new SpaceCapabilityPublicationResult(
                spaceId: $input->spaceId,
                sourceReleaseId: $sourceReleaseId,
                releaseId: $releaseId,
                releaseGeneration: $nextGeneration,
                kind: $input->kind,
                name: $input->name,
                replayed: false,
            );
        });
    }

    /** @param list<mixed> $rows @return array<string, array<string, mixed>> */
    private static function skills(array $rows): array
    {
        $skills = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('A persisted Space skill row is invalid.');
            }
            $name = (string) ($row['name'] ?? '');
            if ($name === '' || isset($skills[$name])) {
                throw new RuntimeException('Persisted Space skills are not uniquely named.');
            }
            $skills[$name] = [
                'name'          => $name,
                'description'   => (string) $row['description'],
                'body'          => (string) $row['body'],
                'enabled'       => self::databaseBoolean($row['enabled']),
                'manifest_json' => (string) $row['manifest_json'],
                'source_digest' => $row['source_digest'] === null ? null : (string) $row['source_digest'],
            ];
        }

        return $skills;
    }

    /** @param array<string, mixed> $manifest */
    private static function putCommand(
        array &$manifest,
        SpaceCapabilityPublicationInput $input,
    ): void {
        if (in_array($input->name, self::RESERVED_COMMANDS, true)) {
            throw new SpaceCapabilityPublicationRejected(
                "Space command /{$input->name} is reserved by the host.",
            );
        }
        $bindings = $manifest['commandBindings'] ?? [];
        if (!is_array($bindings) || !array_is_list($bindings)) {
            throw new RuntimeException('The source Space command registry is invalid.');
        }
        $commands = [];
        foreach ($bindings as $binding) {
            if (!is_array($binding) || array_is_list($binding)) {
                throw new RuntimeException('The source Space command registry is invalid.');
            }
            $actualKeys = array_keys($binding);
            sort($actualKeys, \SORT_STRING);
            if ($actualKeys !== ['command', 'description', 'instructions', 'parametersSchema']) {
                throw new RuntimeException('The source Space command registry is invalid.');
            }
            $name = $binding['command'] ?? null;
            if (!is_string($name) || isset($commands[$name])) {
                throw new RuntimeException('The source Space command registry is invalid.');
            }
            new SpaceCommandBinding(
                name: $name,
                description: (string) ($binding['description'] ?? ''),
                instructions: (string) ($binding['instructions'] ?? ''),
                parametersSchema: is_array($binding['parametersSchema'] ?? null)
                    ? $binding['parametersSchema']
                    : [],
            );
            $commands[$name] = $binding;
        }
        $commands[$input->name] = [
            'command'          => $input->name,
            'description'      => trim($input->description),
            'instructions'     => trim($input->instructions),
            'parametersSchema' => $input->parametersSchema,
        ];
        ksort($commands, \SORT_STRING);
        if (count($commands) > self::MAX_COMMANDS) {
            throw new SpaceCapabilityPublicationRejected(
                'A Space release cannot contain more than 20 commands.',
            );
        }
        $encoded = self::canonicalJson(array_values($commands));
        if (strlen($encoded) > self::MAX_COMMAND_BYTES) {
            throw new SpaceCapabilityPublicationRejected(
                'Enabled Space commands exceed the 50 KB release budget.',
            );
        }
        $manifest['commandBindings'] = array_values($commands);
    }

    /** @param array<string, array<string, mixed>> $skills */
    private static function assertSkillBudget(array $skills): void
    {
        if (count($skills) > self::MAX_SKILLS) {
            throw new SpaceCapabilityPublicationRejected(
                'A Space release cannot contain more than 20 skills.',
            );
        }
        $enabledBytes = 0;
        foreach ($skills as $skill) {
            if ($skill['enabled']) {
                $enabledBytes += strlen((string) $skill['description']) + strlen((string) $skill['body']);
            }
        }
        if ($enabledBytes > self::MAX_ENABLED_SKILL_BYTES) {
            throw new SpaceCapabilityPublicationRejected(
                'Enabled Space skills exceed the 50 KB release budget.',
            );
        }
    }

    /**
     * @param array<string, mixed>                $sourceSnapshot
     * @param array<string, array<string, mixed>> $skills
     * @param string                              $currentMemoryRevision
     * @param SpaceReleaseSeed                    $seed
     * @param string                              $releaseId
     * @param string                              $releaseDigest
     * @param int                                 $nextGeneration
     */
    private static function assertReleaseEnvelope(
        array $sourceSnapshot,
        string $currentMemoryRevision,
        SpaceReleaseSeed $seed,
        string $releaseId,
        string $releaseDigest,
        int $nextGeneration,
        array $skills,
    ): void {
        try {
            SpaceRuntimeSnapshotLoaderActivity::materializePayload(
                spaceId: (string) $sourceSnapshot['space_id'],
                batchId: 'publication-verification:' . $releaseId,
                releaseGeneration: $nextGeneration,
                memoryRevision: $currentMemoryRevision,
                release: [
                    'id'                     => $releaseId,
                    'release_digest'         => $releaseDigest,
                    'model'                  => $seed->model,
                    'prompt'                 => $seed->prompt,
                    'personality_json'       => $seed->personalityJson,
                    'manifest_json'          => $seed->manifestJson,
                    'capability_policy_json' => $seed->capabilityPolicyJson,
                    'artifact_digest'        => $seed->artifactDigest,
                    'created_by'             => self::CREATOR,
                ],
                skillRows: array_map(
                    static fn (array $skill): array => [
                        'name'        => $skill['name'],
                        'description' => $skill['description'],
                        'body'        => $skill['body'],
                        'enabled'     => $skill['enabled'],
                    ],
                    array_values($skills),
                ),
                tools: SpaceToolCatalog::wireDefinitions(),
            );
        } catch (SpaceRuntimeSnapshotPayloadTooLarge $error) {
            throw new SpaceCapabilityPublicationRejected($error->getMessage(), previous: $error);
        }
    }

    /** @param array<string, array<string, mixed>> $skills */
    private static function insertSkills(
        DatabaseInterface $database,
        string $spaceId,
        string $releaseId,
        array $skills,
        int $now,
    ): void {
        $versions = [];
        foreach ($database->query(<<<'SQL'
            SELECT name, MAX(version) AS version
            FROM space_skill_versions
            WHERE space_id = ?
            GROUP BY name
            SQL, [$spaceId])->fetchAll() as $row) {
            if (is_array($row)) {
                $versions[(string) $row['name']] = (int) $row['version'];
            }
        }
        foreach ($skills as $skill) {
            $version = ($versions[$skill['name']] ?? 0) + 1;
            if ($database->execute(<<<'SQL'
                INSERT INTO space_skill_versions (
                    id, space_id, release_id, name, version, description, body,
                    manifest_json, source_digest, enabled, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                SQL, [
                SpaceRecordId::forSeed(implode(':', [
                    self::CREATOR,
                    $spaceId,
                    $releaseId,
                    (string) $skill['name'],
                    (string) $version,
                ])),
                $spaceId,
                $releaseId,
                $skill['name'],
                $version,
                $skill['description'],
                $skill['body'],
                $skill['manifest_json'],
                $skill['source_digest'],
                SqlBoolean::encode((bool) $skill['enabled']),
                $now,
            ]) !== 1) {
                throw new RuntimeException('An immutable Space skill version was not created.');
            }
        }
    }

    private static function publicationId(SpaceCapabilityPublicationInput $input): string
    {
        return self::publicationIdFor(
            $input->spaceId,
            $input->terminalScopeId,
            $input->invocationKey,
        );
    }

    private static function publicationIdFor(
        string $spaceId,
        string $terminalScopeId,
        string $invocationKey,
    ): string {
        SpaceId::assert($spaceId);
        if (trim($terminalScopeId) === ''
            || trim($invocationKey) === ''
            || strlen($terminalScopeId) > 255
            || strlen($invocationKey) > 255
        ) {
            throw new InvalidArgumentException('Space capability publication identity is invalid.');
        }

        return SpaceRecordId::forSeed(self::canonicalJson([
            self::CREATOR,
            $spaceId,
            $terminalScopeId,
            $invocationKey,
        ]));
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $snapshotPayload
     */
    private static function assertSourceRelease(
        array $source,
        array $snapshot,
        array $snapshotPayload,
    ): void {
        try {
            $seed = new SpaceReleaseSeed(
                model: (string) ($source['model'] ?? ''),
                prompt: (string) ($source['prompt'] ?? ''),
                personalityJson: (string) ($source['personality_json'] ?? ''),
                manifestJson: (string) ($source['manifest_json'] ?? ''),
                capabilityPolicyJson: (string) ($source['capability_policy_json'] ?? ''),
                artifactDigest: ($source['artifact_digest'] ?? null) === null
                    ? null
                    : (string) $source['artifact_digest'],
                createdBy: (string) ($source['created_by'] ?? ''),
            );
        } catch (Throwable $error) {
            throw new RuntimeException('The pinned Space release content is invalid.', previous: $error);
        }

        $releaseDigest = (string) ($source['release_digest'] ?? '');
        $expectedModel = trim($seed->model);
        if ((string) ($source['id'] ?? '') !== (string) $snapshot['release_id']
            || (string) ($source['space_id'] ?? '') !== (string) $snapshot['space_id']
            || ($source['status'] ?? null) !== 'active'
            || !preg_match('/\Asha256:[a-f0-9]{64}\z/D', $releaseDigest)
            || !hash_equals($releaseDigest, $seed->digest())
            || ($snapshotPayload['releaseDigest'] ?? null) !== $releaseDigest
            || ($snapshotPayload['model'] ?? null) !== $expectedModel
            || ($snapshotPayload['memoryRevision'] ?? null) !== (string) $snapshot['memory_revision']
            || ($snapshotPayload['capabilityPolicyRevision'] ?? null)
                !== 'sha256:' . hash('sha256', $seed->capabilityPolicyJson)
            || !is_string($snapshotPayload['systemPrompt'] ?? null)
            || trim($snapshotPayload['systemPrompt']) === ''
            || !is_array($snapshotPayload['tools'] ?? null)
            || !array_is_list($snapshotPayload['tools'])
            || !is_array($snapshotPayload['commands'] ?? null)
            || !array_is_list($snapshotPayload['commands'])
        ) {
            throw new RuntimeException('The pinned Space release and runtime snapshot disagree.');
        }
        foreach ($snapshotPayload['tools'] as $tool) {
            if (!is_array($tool)) {
                throw new RuntimeException('The pinned runtime snapshot tool registry is invalid.');
            }
        }
    }

    /**
     * @param DatabaseInterface $database
     * @param string            $spaceId
     * @param string            $eventId
     * @param string            $publicationId
     *
     * @return array{
     *   row: array<string, mixed>,
     *   policy: array<string, mixed>,
     *   input: SpaceCapabilityPublicationInput,
     *   authorizationProvenance: array<string, mixed>
     * }|null
     */
    private static function persistedPublication(
        DatabaseInterface $database,
        string $spaceId,
        string $eventId,
        string $publicationId,
    ): ?array {
        $row = $database->query(<<<'SQL'
            SELECT id, space_id, proposal_id, from_release_id, to_release_id, action,
                   release_generation_before, release_generation_after, actor,
                   policy_decision_json
            FROM space_promotion_events
            WHERE id = ? AND space_id = ?
            SQL, [$eventId, $spaceId])->fetch();
        if (!is_array($row)) {
            return null;
        }

        $policy     = self::objectJson((string) ($row['policy_decision_json'] ?? ''), 'publication policy');
        $policyKeys = array_keys($policy);
        sort($policyKeys, \SORT_STRING);
        if ($policyKeys !== [
            'approved',
            'authorizationProvenance',
            'mode',
            'publicationId',
            'request',
            'requestDigest',
            'result',
            'runtimeSnapshotId',
        ]) {
            throw new RuntimeException('Persisted Space capability publication policy is inconsistent.');
        }

        $request    = $policy['request'] ?? null;
        $provenance = $policy['authorizationProvenance'] ?? null;
        $result     = $policy['result'] ?? null;
        if (!is_array($request)
            || ($request !== [] && array_is_list($request))
            || !is_array($provenance)
            || ($provenance !== [] && array_is_list($provenance))
            || !is_array($result)
            || ($result !== [] && array_is_list($result))
        ) {
            throw new RuntimeException('Persisted Space capability publication data is invalid.');
        }
        $provenance = self::orderedAuthorizationProvenance($provenance);

        try {
            $storedInput = new SpaceCapabilityPublicationInput(
                spaceId: self::requiredString($request, 'spaceId'),
                runtimeSnapshotId: self::requiredString($request, 'runtimeSnapshotId'),
                terminalScopeId: self::requiredString($request, 'terminalScopeId'),
                invocationKey: self::requiredString($request, 'invocationKey'),
                kind: self::requiredString($request, 'kind'),
                name: self::requiredString($request, 'name'),
                description: self::requiredString($request, 'description'),
                instructions: self::requiredString($request, 'instructions'),
                authorizationProvenance: $provenance,
                parametersSchema: self::requiredArray($request, 'parametersSchema'),
                enabled: self::requiredBoolean($request, 'enabled'),
            );
        } catch (Throwable $error) {
            throw new RuntimeException('Persisted Space capability publication request is invalid.', previous: $error);
        }

        $requestJson   = self::canonicalJson($storedInput->payload());
        $requestDigest = self::digest($requestJson);
        $before        = self::databaseInteger($row['release_generation_before'] ?? null);
        $after         = self::databaseInteger($row['release_generation_after'] ?? null);
        $resultKeys    = array_keys($result);
        sort($resultKeys, \SORT_STRING);
        if ((string) ($row['id'] ?? '') !== $eventId
            || (string) ($row['space_id'] ?? '') !== $spaceId
            || ($row['proposal_id'] ?? null) !== null
            || ($row['action'] ?? null) !== 'promote'
            || $before < 0
            || $after !== $before + 1
            || $policy['approved'] !== true
            || $policy['mode'] !== self::CREATOR
            || $policy['publicationId'] !== $publicationId
            || self::publicationId($storedInput) !== $publicationId
            || self::canonicalJson($request) !== $requestJson
            || $policy['requestDigest'] !== $requestDigest
            || $policy['runtimeSnapshotId'] !== $storedInput->runtimeSnapshotId
            || (string) ($row['actor'] ?? '')
                !== (string) $storedInput->authorizationProvenance['actorParticipantKey']
            || $resultKeys !== ['kind', 'name', 'releaseGeneration', 'releaseId', 'sourceReleaseId']
            || $result['sourceReleaseId'] !== (string) ($row['from_release_id'] ?? '')
            || $result['releaseId'] !== (string) ($row['to_release_id'] ?? '')
            || self::databaseInteger($result['releaseGeneration'] ?? null) !== $after
            || $result['kind'] !== $storedInput->kind
            || $result['name'] !== $storedInput->name
        ) {
            throw new RuntimeException('Persisted Space capability publication ledger is inconsistent.');
        }

        $release = $database->query(<<<'SQL'
            SELECT *
            FROM space_releases
            WHERE id = ? AND space_id = ?
            SQL, [(string) $row['to_release_id'], $spaceId])->fetch();
        if (!is_array($release)) {
            throw new RuntimeException('Persisted Space capability publication release is missing.');
        }
        self::assertPersistedRelease(
            $database,
            $release,
            $storedInput,
            $publicationId,
            $requestDigest,
            (string) $row['from_release_id'],
            (string) $row['to_release_id'],
        );

        return [
            'row'                     => $row,
            'policy'                  => $policy,
            'input'                   => $storedInput,
            'authorizationProvenance' => $storedInput->authorizationProvenance,
        ];
    }

    /**
     * @param array<string, mixed>            $release
     * @param DatabaseInterface               $database
     * @param SpaceCapabilityPublicationInput $input
     * @param string                          $publicationId
     * @param string                          $requestDigest
     * @param string                          $sourceReleaseId
     * @param string                          $targetReleaseId
     */
    private static function assertPersistedRelease(
        DatabaseInterface $database,
        array $release,
        SpaceCapabilityPublicationInput $input,
        string $publicationId,
        string $requestDigest,
        string $sourceReleaseId,
        string $targetReleaseId,
    ): void {
        try {
            $seed = new SpaceReleaseSeed(
                model: (string) ($release['model'] ?? ''),
                prompt: (string) ($release['prompt'] ?? ''),
                personalityJson: (string) ($release['personality_json'] ?? ''),
                manifestJson: (string) ($release['manifest_json'] ?? ''),
                capabilityPolicyJson: (string) ($release['capability_policy_json'] ?? ''),
                artifactDigest: ($release['artifact_digest'] ?? null) === null
                    ? null
                    : (string) $release['artifact_digest'],
                createdBy: (string) ($release['created_by'] ?? ''),
            );
        } catch (Throwable $error) {
            throw new RuntimeException('Persisted Space capability release is invalid.', previous: $error);
        }
        $releaseDigest     = (string) ($release['release_digest'] ?? '');
        $expectedReleaseId = SpaceRecordId::forSeed(implode(':', [
            'release:v2',
            $input->spaceId,
            $publicationId,
            $requestDigest,
            $releaseDigest,
        ]));
        if ((string) ($release['id'] ?? '') !== $targetReleaseId
            || $targetReleaseId !== $expectedReleaseId
            || (string) ($release['space_id'] ?? '') !== $input->spaceId
            || (string) ($release['parent_release_id'] ?? '') !== $sourceReleaseId
            || ($release['source_proposal_id'] ?? null) !== null
            || !in_array($release['status'] ?? null, ['active', 'retired'], true)
            || ($release['created_by'] ?? null) !== self::CREATOR
            || !preg_match('/\Asha256:[a-f0-9]{64}\z/D', $releaseDigest)
            || !hash_equals($releaseDigest, $seed->digest())
        ) {
            throw new RuntimeException('Persisted Space capability release lineage is inconsistent.');
        }

        if ($input->kind === SpaceCapabilityPublicationInput::KIND_COMMAND) {
            if (in_array($input->name, self::RESERVED_COMMANDS, true)) {
                throw new RuntimeException('Persisted Space capability release contains a reserved command.');
            }
            $manifest = self::objectJson($seed->manifestJson, 'persisted publication manifest');
            $bindings = $manifest['commandBindings'] ?? null;
            if (!is_array($bindings) || !array_is_list($bindings)) {
                throw new RuntimeException('Persisted Space capability command registry is invalid.');
            }
            $matching = array_values(array_filter(
                $bindings,
                static fn (mixed $binding): bool => is_array($binding)
                    && ($binding['command'] ?? null) === $input->name,
            ));
            $expected = [
                'command'          => $input->name,
                'description'      => trim($input->description),
                'instructions'     => trim($input->instructions),
                'parametersSchema' => $input->parametersSchema,
            ];
            if (count($matching) !== 1
                || self::canonicalJson($matching[0]) !== self::canonicalJson($expected)
            ) {
                throw new RuntimeException('Persisted Space capability command does not match its request.');
            }

            return;
        }

        $skill = $database->query(<<<'SQL'
            SELECT description, body, manifest_json, source_digest, enabled
            FROM space_skill_versions
            WHERE release_id = ? AND space_id = ? AND name = ?
            SQL, [(string) $release['id'], $input->spaceId, $input->name])->fetch();
        if (!is_array($skill)) {
            throw new RuntimeException('Persisted Space capability skill is missing.');
        }
        $skillManifest = self::objectJson(
            (string) ($skill['manifest_json'] ?? ''),
            'persisted publication skill manifest',
        );
        if ((string) ($skill['description'] ?? '') !== trim($input->description)
            || (string) ($skill['body'] ?? '') !== trim($input->instructions)
            || self::databaseBoolean($skill['enabled'] ?? null) !== $input->enabled
            || ($skill['source_digest'] ?? null) !== self::digest(trim($input->instructions))
            || ($skillManifest['source'] ?? null) !== self::CREATOR
            || ($skillManifest['publicationId'] ?? null) !== $publicationId
            || ($skillManifest['requestDigest'] ?? null) !== $requestDigest
        ) {
            throw new RuntimeException('Persisted Space capability skill does not match its request.');
        }
    }

    private static function persistedResult(
        DatabaseInterface $database,
        SpaceCapabilityPublicationInput $input,
        string $eventId,
        string $requestJson,
        string $provenanceJson,
        string $requestDigest,
    ): ?SpaceCapabilityPublicationResult {
        $publicationId = self::publicationId($input);
        $persisted     = self::persistedPublication(
            $database,
            $input->spaceId,
            $eventId,
            $publicationId,
        );
        if ($persisted === null) {
            return null;
        }

        $row    = $persisted['row'];
        $policy = $persisted['policy'];
        if ($policy['requestDigest'] !== $requestDigest
            || self::canonicalJson($policy['request']) !== $requestJson
            || self::canonicalJson($policy['authorizationProvenance']) !== $provenanceJson
            || self::canonicalJson($persisted['input']->payload()) !== $requestJson
            || self::canonicalJson($persisted['authorizationProvenance']) !== $provenanceJson
        ) {
            throw new SpaceCapabilityPublicationRejected(
                'Space capability idempotency key was already used for another request.',
            );
        }
        $result = $policy['result'] ?? null;

        return new SpaceCapabilityPublicationResult(
            spaceId: $input->spaceId,
            sourceReleaseId: (string) $row['from_release_id'],
            releaseId: (string) $row['to_release_id'],
            releaseGeneration: (int) $row['release_generation_after'],
            kind: $input->kind,
            name: $input->name,
            replayed: true,
        );
    }

    /** @param array<string, mixed> $value */
    private static function requiredString(array $value, string $key): string
    {
        $result = $value[$key] ?? null;
        if (!is_string($result)) {
            throw new RuntimeException("Persisted publication field {$key} must be a string.");
        }

        return $result;
    }

    /** @param array<string, mixed> $value @return array<string, mixed> */
    private static function requiredArray(array $value, string $key): array
    {
        $result = $value[$key] ?? null;
        if (!is_array($result)) {
            throw new RuntimeException("Persisted publication field {$key} must be an object.");
        }

        return $result;
    }

    /** @param array<string, mixed> $value */
    private static function requiredBoolean(array $value, string $key): bool
    {
        $result = $value[$key] ?? null;
        if (!is_bool($result)) {
            throw new RuntimeException("Persisted publication field {$key} must be a boolean.");
        }

        return $result;
    }

    /** @param array<string, mixed> $value @return array<string, mixed> */
    private static function orderedAuthorizationProvenance(array $value): array
    {
        $keys = array_keys($value);
        sort($keys, \SORT_STRING);
        if ($keys !== [
            'actorParticipantKey',
            'authorization',
            'batchId',
            'quoteSha256',
            'requestSha256',
            'requestUpdateId',
            'spaceId',
        ]) {
            throw new RuntimeException('Persisted Space capability authority is inconsistent.');
        }

        return [
            'spaceId'             => $value['spaceId'] ?? null,
            'batchId'             => $value['batchId'] ?? null,
            'authorization'       => $value['authorization'] ?? null,
            'actorParticipantKey' => $value['actorParticipantKey'] ?? null,
            'requestUpdateId'     => $value['requestUpdateId'] ?? null,
            'requestSha256'       => $value['requestSha256'] ?? null,
            'quoteSha256'         => $value['quoteSha256'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private static function objectJson(string $json, string $label): array
    {
        try {
            $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            throw new RuntimeException("Space {$label} is invalid JSON.", previous: $error);
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new RuntimeException("Space {$label} must be an object.");
        }

        return $decoded;
    }

    /** @return array<string, mixed> */
    private static function decodeObject(string $json): array
    {
        return self::objectJson($json, 'canonical publication data');
    }

    private static function canonicalJson(mixed $value): string
    {
        return json_encode(
            self::canonicalize($value),
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, \SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }

    private static function digest(string $canonical): string
    {
        return 'sha256:' . hash('sha256', $canonical);
    }

    private static function databaseBoolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }

    private static function databaseInteger(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A-?\d+\z/D', $value) === 1) {
            return (int) $value;
        }

        throw new RuntimeException('Persisted Space capability generation is invalid.');
    }
}
