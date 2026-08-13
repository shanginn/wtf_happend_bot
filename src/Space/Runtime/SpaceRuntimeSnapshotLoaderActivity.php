<?php

declare(strict_types=1);

namespace Bot\Space\Runtime;

use Bot\Space\Persistence\SpaceReleaseSeed;
use Bot\Space\Workflow\SpaceAgentRuntime;
use Cycle\Database\DatabaseInterface;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Materializes and durably pins one complete runtime view per Space batch.
 * Retried activities therefore cannot drift to a release promoted mid-retry.
 */
final readonly class SpaceRuntimeSnapshotLoaderActivity implements SpaceRuntimeSnapshotLoaderActivityInterface
{
    private const int MAX_SKILLS              = 20;
    private const int MAX_ENABLED_SKILL_BYTES = 50_000;
    private const int MAX_COMMANDS            = 20;
    private const int MAX_COMMAND_BYTES       = 50_000;
    private const int MAX_SNAPSHOT_BYTES      = 512_000;

    /** @param list<array<string, mixed>> $tools */
    public function __construct(
        private DatabaseInterface $database,
        private array $tools,
        private string $fallbackModel = SpaceAgentRuntime::MODEL,
    ) {}

    /**
     * Builds and validates the exact payload that a future batch will pin.
     * Publication uses this same path before changing the active release, so
     * no release can be activated if the loader would reject it afterward.
     *
     * @param array<string, mixed>       $release
     * @param list<mixed>                $skillRows
     * @param list<array<string, mixed>> $tools
     * @param string                     $spaceId
     * @param string                     $batchId
     * @param int                        $releaseGeneration
     * @param string                     $memoryRevision
     * @param string                     $fallbackModel
     *
     * @return array<string, mixed>
     */
    public static function materializePayload(
        string $spaceId,
        string $batchId,
        int $releaseGeneration,
        string $memoryRevision,
        array $release,
        array $skillRows,
        array $tools,
        string $fallbackModel = SpaceAgentRuntime::MODEL,
    ): array {
        $personality = self::objectJson((string) ($release['personality_json'] ?? ''), 'personality');
        $manifest    = self::objectJson((string) ($release['manifest_json'] ?? ''), 'manifest');

        $skills       = [];
        $skillContent = [];
        $skillBytes   = 0;
        foreach ($skillRows as $skill) {
            if (!is_array($skill)) {
                throw new RuntimeException('The active Space release has an invalid skill row.');
            }
            $normalized = [
                'name'        => (string) ($skill['name'] ?? ''),
                'description' => (string) ($skill['description'] ?? ''),
                'body'        => (string) ($skill['body'] ?? ''),
                'enabled'     => self::databaseBoolean($skill['enabled'] ?? null),
            ];
            $skillContent[] = $normalized;
            if ($normalized['enabled']) {
                $skills[] = [
                    'name'        => $normalized['name'],
                    'description' => $normalized['description'],
                    'body'        => $normalized['body'],
                ];
                $skillBytes += strlen($normalized['description']) + strlen($normalized['body']);
            }
        }
        self::assertSkillsIntegrity($manifest, $skillContent);
        if (count($skillContent) > self::MAX_SKILLS || $skillBytes > self::MAX_ENABLED_SKILL_BYTES) {
            throw new RuntimeException('The active Space release exceeds the enabled skill budget.');
        }

        $commands                   = self::commands($manifest);
        $capsules                   = self::capsules($manifest);
        $capsuleRuntimeImageBuildId = null;
        $releaseDigest              = (string) ($release['release_digest'] ?? '');
        if (!preg_match('/\Asha256:[a-f0-9]{64}\z/D', $releaseDigest)) {
            throw new RuntimeException('The active Space release has an invalid digest.');
        }

        $capabilityPolicyJson = (string) ($release['capability_policy_json'] ?? '');
        SpaceCapabilityPolicy::assertFixed($capabilityPolicyJson);
        $computedReleaseDigest = (new SpaceReleaseSeed(
            model: (string) ($release['model'] ?? ''),
            prompt: (string) ($release['prompt'] ?? ''),
            personalityJson: (string) ($release['personality_json'] ?? ''),
            manifestJson: (string) ($release['manifest_json'] ?? ''),
            capabilityPolicyJson: $capabilityPolicyJson,
            artifactDigest: ($release['artifact_digest'] ?? null) === null
                ? null
                : (string) $release['artifact_digest'],
            createdBy: (string) ($release['created_by'] ?? ''),
        ))->digest();
        if (!hash_equals($releaseDigest, $computedReleaseDigest)) {
            throw new RuntimeException('The active Space release content does not match its digest.');
        }

        $releaseId = (string) ($release['id'] ?? '');
        $payload   = [
            'snapshotId' => 'snp_' . substr(hash('sha256', implode("\0", [
                $spaceId,
                $batchId,
                $releaseId,
                (string) $releaseGeneration,
                $memoryRevision,
            ])), 0, 40),
            'spaceId'       => $spaceId,
            'releaseId'     => $releaseId,
            'releaseDigest' => $releaseDigest,
            'model'         => trim((string) ($release['model'] ?? '')) ?: $fallbackModel,
            'systemPrompt'  => SpacePrompt::build(
                releaseId: $releaseId,
                overlay: (string) ($release['prompt'] ?? ''),
                personality: $personality,
                skills: $skills,
                capsules: $capsules,
                commands: $commands,
            ),
            'tools'                      => $tools,
            'commands'                   => $commands,
            'capsuleArtifactRefs'        => $capsules,
            'capsuleRuntimeImageBuildId' => $capsuleRuntimeImageBuildId,
            'memoryRevision'             => $memoryRevision,
            'capabilityPolicyRevision'   => 'sha256:' . hash('sha256', $capabilityPolicyJson),
        ];
        self::encodePayload($payload);

        return $payload;
    }

    public function loadSnapshot(SpaceRuntimeSnapshotRequest $request): SpaceRuntimeSnapshot
    {
        $cached = $this->cached($request);
        if ($cached !== null) {
            return $cached;
        }

        $space = $this->database->query(<<<'SQL'
            SELECT active_release_id, release_generation, memory_revision
            FROM agent_spaces
            WHERE id = ? AND status = 'active'
            SQL, [$request->spaceId])->fetch();
        if (!is_array($space) || !is_string($space['active_release_id'] ?? null)) {
            throw new RuntimeException('The requested Space has no active release.');
        }

        $release = $this->database->query(<<<'SQL'
            SELECT *
            FROM space_releases
            WHERE id = ? AND space_id = ?
            SQL, [$space['active_release_id'], $request->spaceId])->fetch();
        if (!is_array($release)) {
            throw new RuntimeException('The active Space release is missing.');
        }

        $skillRows = $this->database->query(<<<'SQL'
            SELECT name, description, body, enabled
            FROM space_skill_versions
            WHERE release_id = ? AND space_id = ?
            ORDER BY name ASC
            SQL, [$release['id'], $request->spaceId])->fetchAll();
        $payload = self::materializePayload(
            spaceId: $request->spaceId,
            batchId: $request->batchId,
            releaseGeneration: (int) $space['release_generation'],
            memoryRevision: (string) $space['memory_revision'],
            release: $release,
            skillRows: $skillRows,
            tools: $this->tools,
            fallbackModel: $this->fallbackModel,
        );
        $encoded    = self::encodePayload($payload);
        $parameters = [
            $payload['snapshotId'],
            $request->spaceId,
            $request->batchId,
            $release['id'],
            $space['release_generation'],
            $space['memory_revision'],
            $encoded,
            time(),
        ];
        $this->database->execute(<<<'SQL'
            INSERT INTO space_runtime_snapshots (
                id, space_id, batch_id, release_id, release_generation,
                memory_revision, payload_json, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (space_id, batch_id) DO NOTHING
            SQL, $parameters);

        return $this->cached($request)
            ?? throw new RuntimeException('The pinned Space runtime snapshot was not persisted.');
    }

    /** @param array<string, mixed> $payload */
    private static function encodePayload(array $payload): string
    {
        $encoded = json_encode(
            $payload,
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
        if (strlen($encoded) > self::MAX_SNAPSHOT_BYTES) {
            throw new SpaceRuntimeSnapshotPayloadTooLarge(
                'The active Space runtime snapshot exceeds the safe payload budget.',
            );
        }

        return $encoded;
    }

    /** @return array<string, mixed> */
    private static function objectJson(string $json, string $label): array
    {
        try {
            $value = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            throw new RuntimeException("Space release {$label} is invalid JSON.", previous: $error);
        }
        // json_decode('{}', true) and json_decode('[]', true) both produce an
        // empty PHP array. Empty is valid here because the persisted JSON was
        // already checked syntactically; non-empty lists are not objects.
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new RuntimeException("Space release {$label} must be an object.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed>                                                        $manifest
     * @param list<array{name: string, description: string, body: string, enabled: bool}> $skills
     */
    private static function assertSkillsIntegrity(array $manifest, array $skills): void
    {
        $expected = $manifest['skillsDigest'] ?? null;
        if ($expected === null && $skills === []) {
            return;
        }
        if (!is_string($expected) || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $expected) !== 1) {
            throw new RuntimeException('The active Space release has no valid skills digest.');
        }
        $actual = 'sha256:' . hash('sha256', json_encode(
            $skills,
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        ));
        if (!hash_equals($expected, $actual)) {
            throw new RuntimeException('The active Space skills do not match the release manifest.');
        }
    }

    private static function databaseBoolean(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || $value === '1'
            || $value === 't'
            || $value === 'true';
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return list<array<string, mixed>>
     */
    private static function capsules(array $manifest): array
    {
        $capsules = $manifest['capsules'] ?? [];
        if (!is_array($capsules) || !array_is_list($capsules)) {
            throw new RuntimeException('Space release capsules must be a list.');
        }
        if ($capsules !== []) {
            throw new RuntimeException('Executable capsules are disabled in this release.');
        }

        return [];
    }

    /**
     * Commands are complete immutable specifications in the release manifest.
     * They are intentionally independent from ordinary always-on Space skills.
     *
     * @param array<string, mixed> $manifest
     *
     * @return list<SpaceCommandBinding>
     */
    private static function commands(array $manifest): array
    {
        if (!array_key_exists('commandBindings', $manifest)) {
            return [];
        }

        $bindings = $manifest['commandBindings'];
        if (!is_array($bindings) || !array_is_list($bindings)) {
            throw new RuntimeException('Space release command bindings must be a list.');
        }
        if (count($bindings) > self::MAX_COMMANDS) {
            throw new RuntimeException('A Space release cannot contain more than 20 command bindings.');
        }
        if (strlen(json_encode(
            $bindings,
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        )) > self::MAX_COMMAND_BYTES) {
            throw new RuntimeException('Enabled Space commands exceed the 50 KB release budget.');
        }

        $commands     = [];
        $previousName = null;
        $expectedKeys = ['command', 'description', 'instructions', 'parametersSchema'];
        sort($expectedKeys, \SORT_STRING);
        foreach ($bindings as $index => $binding) {
            if (!is_array($binding) || array_is_list($binding)) {
                throw new RuntimeException(
                    sprintf('Space command binding %d must be an object.', $index),
                );
            }
            $actualKeys = array_keys($binding);
            sort($actualKeys, \SORT_STRING);
            if ($actualKeys !== $expectedKeys) {
                throw new RuntimeException(
                    sprintf('Space command binding %d has unsupported or missing fields.', $index),
                );
            }

            $name         = $binding['command'];
            $description  = $binding['description'];
            $instructions = $binding['instructions'];
            $schema       = $binding['parametersSchema'];
            if (!is_string($name)
                || !is_string($description)
                || !is_string($instructions)
                || !is_array($schema)
                || $name !== SpaceCommandBinding::normalizeName($name)
            ) {
                throw new RuntimeException(
                    sprintf('Space command binding %d is not canonical.', $index),
                );
            }
            if ($previousName !== null && strcmp($previousName, $name) >= 0) {
                throw new RuntimeException(
                    'Space release command bindings must be uniquely sorted by canonical name.',
                );
            }
            $previousName = $name;

            try {
                $command = new SpaceCommandBinding(
                    name: $name,
                    description: $description,
                    instructions: $instructions,
                    parametersSchema: $schema,
                );
            } catch (InvalidArgumentException $error) {
                throw new RuntimeException(
                    sprintf('Space command "%s" is invalid.', $name),
                    previous: $error,
                );
            }
            $commands[] = $command;
        }

        return $commands;
    }

    /** @param array<string, mixed> $value */
    private static function string(array $value, string $key): string
    {
        $result = $value[$key] ?? null;
        if (!is_string($result) || $result === '') {
            throw new RuntimeException("Snapshot field {$key} is invalid.");
        }

        return $result;
    }

    /** @param array<string, mixed> $value @return list<array<string, mixed>> */
    private static function list(array $value, string $key): array
    {
        $result = $value[$key] ?? null;
        if (!is_array($result) || !array_is_list($result)) {
            throw new RuntimeException("Snapshot field {$key} is invalid.");
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $value
     * @param string               $key
     *
     * @return list<SpaceCommandBinding>
     */
    private static function commandList(array $value, string $key): array
    {
        $commands = [];
        foreach (self::list($value, $key) as $index => $command) {
            if (!is_array($command)) {
                throw new RuntimeException(sprintf('Snapshot command %d is invalid.', $index));
            }
            $schema = $command['parametersSchema'] ?? null;
            if (!is_array($schema)) {
                throw new RuntimeException(sprintf('Snapshot command %d schema is invalid.', $index));
            }

            try {
                $commands[] = new SpaceCommandBinding(
                    name: self::string($command, 'name'),
                    description: self::string($command, 'description'),
                    instructions: self::string($command, 'instructions'),
                    parametersSchema: $schema,
                );
            } catch (InvalidArgumentException $error) {
                throw new RuntimeException(
                    sprintf('Snapshot command %d is invalid.', $index),
                    previous: $error,
                );
            }
        }

        return $commands;
    }

    private function cached(SpaceRuntimeSnapshotRequest $request): ?SpaceRuntimeSnapshot
    {
        $row = $this->database->query(<<<'SQL'
            SELECT payload_json
            FROM space_runtime_snapshots
            WHERE space_id = ? AND batch_id = ?
            SQL, [$request->spaceId, $request->batchId])->fetch();
        if (!is_array($row)) {
            return null;
        }

        try {
            $payload = json_decode((string) $row['payload_json'], true, flags: \JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            throw new RuntimeException('A pinned Space runtime snapshot is corrupt.', previous: $error);
        }
        if (!is_array($payload)) {
            throw new RuntimeException('A pinned Space runtime snapshot is not an object.');
        }
        if (($payload['spaceId'] ?? null) !== $request->spaceId) {
            throw new RuntimeException('A pinned runtime snapshot belongs to another Space.');
        }

        $capsuleArtifactRefs        = self::list($payload, 'capsuleArtifactRefs');
        $capsuleRuntimeImageBuildId = $payload['capsuleRuntimeImageBuildId'] ?? null;
        if ($capsuleRuntimeImageBuildId !== null && !is_string($capsuleRuntimeImageBuildId)) {
            throw new RuntimeException('Snapshot field capsuleRuntimeImageBuildId is invalid.');
        }
        if ($capsuleArtifactRefs !== []) {
            throw new RuntimeException('Executable capsules are disabled in this release.');
        }

        return new SpaceRuntimeSnapshot(
            snapshotId: self::string($payload, 'snapshotId'),
            spaceId: self::string($payload, 'spaceId'),
            releaseId: self::string($payload, 'releaseId'),
            releaseDigest: self::string($payload, 'releaseDigest'),
            model: self::string($payload, 'model'),
            systemPrompt: self::string($payload, 'systemPrompt'),
            tools: self::list($payload, 'tools'),
            commands: self::commandList($payload, 'commands'),
            capsuleArtifactRefs: $capsuleArtifactRefs,
            capsuleRuntimeImageBuildId: $capsuleRuntimeImageBuildId,
            memoryRevision: self::string($payload, 'memoryRevision'),
            capabilityPolicyRevision: self::string($payload, 'capabilityPolicyRevision'),
        );
    }
}
