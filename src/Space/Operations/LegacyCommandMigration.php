<?php

declare(strict_types=1);

namespace Bot\Space\Operations;

use Bot\Config\TemporalExecutionIdentity;
use Bot\Entity\RuntimeTool;
use Bot\Llm\Runtime\RuntimeCapabilityValidator;
use Bot\Space\Persistence\SpaceRecordId;
use Bot\Space\Persistence\SpaceReleaseSeed;
use Bot\Space\Persistence\SqlBoolean;
use Bot\Space\Runtime\SpaceCommandBinding;
use Bot\Space\Runtime\SpaceRuntimeSnapshotLoaderActivity;
use Bot\Space\Runtime\SpaceRuntimeSnapshotRequest;
use Bot\Space\Tools\SpaceToolCatalog;
use Cycle\Database\DatabaseInterface;
use RuntimeException;
use stdClass;

/**
 * Explicit one-shot bridge from the retired chat-scoped runtime_tools store to
 * immutable Space releases. The live runtime never calls this class and never
 * reads the legacy tables after migration.
 */
final readonly class LegacyCommandMigration
{
    private const string CREATOR              = 'manual-command-migration-v1';
    private const string OBSOLETE_SKILL       = 'command-sync-reminder';
    private const int MAX_SKILLS              = 20;
    private const int MAX_ENABLED_SKILL_BYTES = 50_000;
    private const array RESERVED_COMMANDS     = ['clear', 'pause', 'resume'];
    private const array DIRECT_REPLY_COMMANDS = [
        'dimannews',
        'iddqd_dildak',
        'mezhdustroch',
        'ochkometer',
        'whois',
        'zaemny_penis',
    ];
    private const array RETIRED_COMMANDS = [
        'sharada-mutation',
        'update_bot_commands',
    ];

    public function __construct(
        private DatabaseInterface $database,
        private string $botInstanceId,
    ) {
        if (trim($botInstanceId) === '') {
            throw new RuntimeException('Legacy command migration requires a bot instance ID.');
        }
    }

    /**
     * @return array{
     *   mode: string,
     *   targetSpaces: int,
     *   enabledLegacyTools: int,
     *   migratableCommands: int,
     *   retiredCommands: int,
     *   alreadyMigrated: int
     * }
     */
    public function preview(): array
    {
        $targets   = $this->targets($this->database, lock: false);
        $inventory = $this->legacyInventory($this->database, $targets, lock: false);

        return [
            'mode'               => 'preview',
            'targetSpaces'       => count($targets),
            'enabledLegacyTools' => array_sum(array_column($targets, 'enabled_tool_count')),
            'migratableCommands' => $inventory['migratable'],
            'retiredCommands'    => $inventory['retired'],
            'alreadyMigrated'    => count(array_filter(
                $targets,
                fn (array $target): bool => $this->isMigratedManifest((string) $target['manifest_json']),
            )),
        ];
    }

    /**
     * @param ?int   $now
     * @param string $hostReleaseId
     *
     * @return array{
     *   mode: string,
     *   hostPhase: string,
     *   targetSpaces: int,
     *   migratedSpaces: int,
     *   skippedSpaces: int,
     *   migratedCommands: int,
     *   retiredCommands: int
     * }
     */
    public function apply(string $hostReleaseId, ?int $now = null): array
    {
        TemporalExecutionIdentity::assertReleaseId($hostReleaseId);
        $now ??= time();

        return $this->database->transaction(function (DatabaseInterface $database) use (
            $hostReleaseId,
            $now,
        ): array {
            // Serialize the data cutover with prepare/authorize/abort. An
            // external pre-check is not a fence: without this shared lock an
            // abort could restore the host while this transaction still
            // activates command data for the aborted candidate.
            $database->query(
                "SELECT pg_advisory_xact_lock(hashtext('host-release-control'))",
            )->fetch();
            $hostPhase = self::hostReleaseMigrationPhase($database, $hostReleaseId);
            $database->query(
                "SELECT pg_advisory_xact_lock(hashtext('manual-command-migration-v1'))",
            )->fetch();

            $targets          = $this->targets($database, lock: true);
            $inventory        = $this->legacyInventory($database, $targets, lock: true);
            $migratedSpaces   = 0;
            $skippedSpaces    = 0;
            $migratedCommands = 0;
            foreach ($targets as $target) {
                if ($this->isMigratedManifest((string) $target['manifest_json'])) {
                    ++$skippedSpaces;

                    continue;
                }

                $migratedCommands += $this->migrateSpace($database, $target, $now);
                ++$migratedSpaces;
            }

            if (in_array($hostPhase, ['authorized', 'ingress-retired'], true)) {
                if ($migratedSpaces !== 0) {
                    throw new RuntimeException(
                        'An authorized host release may only replay an already completed command migration.',
                    );
                }
            } elseif ($hostPhase === 'prepared') {
                $authorized = $database->execute(<<<'SQL'
                    UPDATE host_release_control
                    SET phase = 'authorized', authorized_at = COALESCE(authorized_at, ?), updated_at = ?
                    WHERE singleton = true
                      AND desired_release_id = ?
                      AND phase = 'prepared'
                    SQL, [$now, $now, $hostReleaseId]);
                if ($authorized !== 1) {
                    throw new RuntimeException(
                        'Command migration lost the atomic host authorization fence.',
                    );
                }
                $hostPhase = 'authorized';
            }

            return [
                'mode'             => 'applied',
                'hostPhase'        => $hostPhase,
                'targetSpaces'     => count($targets),
                'migratedSpaces'   => $migratedSpaces,
                'skippedSpaces'    => $skippedSpaces,
                'migratedCommands' => $migratedCommands,
                'retiredCommands'  => $inventory['retired'],
            ];
        });
    }

    private static function hostReleaseMigrationPhase(
        DatabaseInterface $database,
        string $hostReleaseId,
    ): string {
        $row = $database->query(<<<'SQL'
            SELECT desired_release_id, active_release_id, phase
            FROM host_release_control
            WHERE singleton = true
            SQL)->fetch();
        if (!is_array($row) || (string) $row['desired_release_id'] !== $hostReleaseId) {
            throw new RuntimeException(
                'Legacy commands can migrate only for the exact desired host release.',
            );
        }

        $phase = (string) $row['phase'];
        if ($phase === 'active') {
            if ((string) ($row['active_release_id'] ?? '') !== $hostReleaseId) {
                throw new RuntimeException(
                    'Legacy commands can migrate only for the exact active host release.',
                );
            }

            return $phase;
        }
        if (in_array($phase, ['prepared', 'authorized', 'ingress-retired'], true)) {
            return $phase;
        }

        throw new RuntimeException(
            'Legacy commands cannot migrate in this host release phase.',
        );
    }

    /** @return array<string, mixed> */
    private static function objectJson(string $json, string $label): array
    {
        $value = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new RuntimeException("{$label} must be a JSON object.");
        }

        return $value;
    }

    private static function databaseBoolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }

    /** @param array<mixed> $value */
    private static function digest(array $value): string
    {
        return 'sha256:' . hash('sha256', self::json($value));
    }

    /** @param array<mixed> $value */
    private static function json(array $value): string
    {
        return json_encode(
            $value,
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * Build the immutable new release content without reusing Space skills as
     * command storage. Ordinary enabled skills stay ordinary skills; complete
     * command specifications live only in the release manifest.
     *
     * @param list<array<string, mixed>> $existingRows
     * @param list<array<string, mixed>> $legacyRows
     *
     * @return array{
     *   skills: array<string, array{
     *     name: string,
     *     description: string,
     *     body: string,
     *     enabled: true,
     *     manifest_json: string,
     *     source_digest: ?string
     *   }>,
     *   commands: array<string, array{
     *     command: string,
     *     description: string,
     *     instructions: string,
     *     parametersSchema: array<string, mixed>
     *   }>,
     *   legacyDigestPayload: list<array<string, mixed>>
     * }
     */
    private static function releaseContent(array $existingRows, array $legacyRows): array
    {
        $skills = [];
        foreach ($existingRows as $row) {
            if (!is_array($row)
                || !self::databaseBoolean($row['enabled'] ?? false)
                || (string) $row['name'] === self::OBSOLETE_SKILL
            ) {
                continue;
            }
            $name          = (string) $row['name'];
            $skills[$name] = [
                'name'          => $name,
                'description'   => (string) $row['description'],
                'body'          => (string) $row['body'],
                'enabled'       => true,
                'manifest_json' => (string) $row['manifest_json'],
                'source_digest' => $row['source_digest'] === null ? null : (string) $row['source_digest'],
            ];
        }

        $commands            = [];
        $legacyDigestPayload = [];
        foreach ($legacyRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $legacyName = (string) $row['name'];
            if (in_array($legacyName, self::RETIRED_COMMANDS, true)) {
                continue;
            }
            if (!in_array($legacyName, self::DIRECT_REPLY_COMMANDS, true)) {
                throw new RuntimeException(sprintf(
                    'Enabled legacy tool "%s" has no explicit migration policy.',
                    $legacyName,
                ));
            }
            $tool = new RuntimeTool(
                chatId: (int) $row['chat_id'],
                name: (string) $row['name'],
                description: (string) $row['description'],
                parametersSchema: (string) $row['parameters_schema'],
                instructions: (string) $row['instructions'],
                enabled: self::databaseBoolean($row['enabled']),
                createdAt: (int) $row['created_at'],
                updatedAt: (int) $row['updated_at'],
            );
            $error = RuntimeCapabilityValidator::storedRuntimeToolError($tool);
            if ($error !== null) {
                throw new RuntimeException(sprintf(
                    'Legacy command "%s" is invalid: %s',
                    $tool->name,
                    $error,
                ));
            }

            $commandName = str_replace('-', '_', RuntimeCapabilityValidator::normalizeName($tool->name));
            if (in_array($commandName, self::RESERVED_COMMANDS, true)) {
                throw new RuntimeException(sprintf('Legacy command /%s is reserved by the host.', $commandName));
            }
            if (isset($commands[$commandName])) {
                throw new RuntimeException(sprintf(
                    'Legacy commands collide after Telegram normalization at /%s.',
                    $commandName,
                ));
            }

            $schema = RuntimeCapabilityValidator::decodeParametersSchema($tool->parametersSchema);
            if (($schema['properties'] ?? null) instanceof stdClass) {
                // The legacy validator preserves an empty JSON object as
                // stdClass for wire encoding. Immutable Space command
                // manifests use the equivalent canonical PHP schema shape.
                $schema['properties'] = [];
            }
            // Constructor performs the exact snapshot/runtime command validation.
            new SpaceCommandBinding(
                name: $commandName,
                description: $tool->description,
                instructions: $tool->instructions,
                parametersSchema: $schema,
            );
            $commands[$commandName] = [
                'command'          => $commandName,
                'description'      => $tool->description,
                'instructions'     => $tool->instructions,
                'parametersSchema' => $schema,
            ];
            $legacyDigestPayload[] = [
                'name'             => $tool->name,
                'description'      => $tool->description,
                'parametersSchema' => $schema,
                'instructions'     => $tool->instructions,
            ];
        }

        ksort($skills, \SORT_STRING);
        ksort($commands, \SORT_STRING);
        if (array_keys($commands) !== self::DIRECT_REPLY_COMMANDS) {
            throw new RuntimeException(
                'Enabled legacy tools do not match the exact six-command migration inventory.',
            );
        }
        if (count($skills) > self::MAX_SKILLS) {
            throw new RuntimeException('Migrated Space release exceeds the 20-skill limit.');
        }
        $enabledBytes = array_sum(array_map(
            static fn (array $skill): int => strlen($skill['description']) + strlen($skill['body']),
            $skills,
        ));
        if ($enabledBytes > self::MAX_ENABLED_SKILL_BYTES) {
            throw new RuntimeException('Migrated Space release exceeds the enabled skill byte budget.');
        }

        return [
            'skills'              => $skills,
            'commands'            => $commands,
            'legacyDigestPayload' => $legacyDigestPayload,
        ];
    }

    /**
     * @param list<array<string, mixed>> $targets
     * @param DatabaseInterface          $database
     * @param bool                       $lock
     *
     * @return array{migratable: int, retired: int}
     */
    private function legacyInventory(
        DatabaseInterface $database,
        array $targets,
        bool $lock,
    ): array {
        $migratable = 0;
        $retired    = 0;
        foreach ($targets as $target) {
            $sql = <<<'SQL'
                SELECT name
                FROM runtime_tools
                WHERE CAST(chat_id AS text) = ? AND enabled = true
                ORDER BY name ASC
                SQL;
            if ($lock) {
                $sql .= ' FOR SHARE';
            }
            foreach ($database->query($sql, [$target['external_conversation_id']])->fetchAll() as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = (string) $row['name'];
                if (in_array($name, self::DIRECT_REPLY_COMMANDS, true)) {
                    ++$migratable;

                    continue;
                }
                if (in_array($name, self::RETIRED_COMMANDS, true)) {
                    ++$retired;

                    continue;
                }

                throw new RuntimeException(sprintf(
                    'Enabled legacy tool "%s" has no explicit migration policy.',
                    $name,
                ));
            }
        }

        return ['migratable' => $migratable, 'retired' => $retired];
    }

    /**
     * @param DatabaseInterface $database
     * @param bool              $lock
     *
     * @return list<array<string, mixed>>
     */
    private function targets(DatabaseInterface $database, bool $lock): array
    {
        $rows = $database->query(<<<'SQL'
            SELECT
                space.id AS space_id,
                space.active_release_id,
                space.release_generation,
                binding.external_conversation_id,
                release.manifest_json,
                COUNT(tool.id) AS enabled_tool_count
            FROM agent_spaces AS space
            JOIN space_bindings AS binding
              ON binding.space_id = space.id
             AND binding.bot_instance_id = ?
             AND binding.platform = 'telegram'
             AND binding.external_thread_id = ''
            JOIN space_releases AS release
              ON release.id = space.active_release_id
             AND release.space_id = space.id
             AND release.status = 'active'
            JOIN runtime_tools AS tool
              ON CAST(tool.chat_id AS text) = binding.external_conversation_id
             AND tool.enabled = true
            WHERE space.status = 'active'
            GROUP BY
                space.id,
                space.active_release_id,
                space.release_generation,
                binding.external_conversation_id,
                release.manifest_json
            ORDER BY space.id ASC
            SQL, [$this->botInstanceId])->fetchAll();

        $targets = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row['enabled_tool_count'] = (int) $row['enabled_tool_count'];
            $targets[]                 = $row;
        }

        if ($lock) {
            foreach ($targets as $target) {
                $locked = $database->query(<<<'SQL'
                    SELECT active_release_id, release_generation
                    FROM agent_spaces
                    WHERE id = ? AND status = 'active'
                    FOR UPDATE
                    SQL, [$target['space_id']])->fetch();
                if (!is_array($locked)
                    || (string) $locked['active_release_id'] !== (string) $target['active_release_id']
                    || (int) $locked['release_generation'] !== (int) $target['release_generation']
                ) {
                    throw new RuntimeException('A Space release changed while command migration was acquiring its fence.');
                }
            }
        }

        return $targets;
    }

    /**
     * @param array<string, mixed> $target
     * @param DatabaseInterface    $database
     * @param int                  $now
     */
    private function migrateSpace(DatabaseInterface $database, array $target, int $now): int
    {
        $spaceId         = (string) $target['space_id'];
        $parentReleaseId = (string) $target['active_release_id'];
        $parent          = $database->query(<<<'SQL'
            SELECT *
            FROM space_releases
            WHERE id = ? AND space_id = ? AND status = 'active'
            FOR UPDATE
            SQL, [$parentReleaseId, $spaceId])->fetch();
        if (!is_array($parent)) {
            throw new RuntimeException('The command migration baseline release is no longer active.');
        }

        $legacyRows = $database->query(<<<'SQL'
            SELECT id, chat_id, name, description, parameters_schema, instructions,
                   enabled, created_at, updated_at
            FROM runtime_tools
            WHERE CAST(chat_id AS text) = ? AND enabled = true
            ORDER BY name ASC
            FOR SHARE
            SQL, [$target['external_conversation_id']])->fetchAll();
        if ($legacyRows === []) {
            throw new RuntimeException('The migration target lost all enabled legacy commands.');
        }

        $existingRows = $database->query(<<<'SQL'
            SELECT name, description, body, manifest_json, source_digest, enabled
            FROM space_skill_versions
            WHERE release_id = ? AND space_id = ?
            ORDER BY name ASC
            FOR SHARE
            SQL, [$parentReleaseId, $spaceId])->fetchAll();

        [
            'skills'              => $skills,
            'commands'            => $commands,
            'legacyDigestPayload' => $legacyDigestPayload,
        ] = self::releaseContent($existingRows, $legacyRows);

        $manifest = self::objectJson((string) $parent['manifest_json'], 'baseline manifest');
        if (($manifest['commandBindings'] ?? []) !== []) {
            throw new RuntimeException('The migration baseline already contains non-legacy command bindings.');
        }
        $manifest['commandBindings'] = array_values($commands);
        $manifest['skillsDigest']    = self::digest(array_map(
            static fn (array $skill): array => [
                'name'        => $skill['name'],
                'description' => $skill['description'],
                'body'        => $skill['body'],
                'enabled'     => true,
            ],
            array_values($skills),
        ));
        $manifest['legacyCommandMigration'] = [
            'version'             => 1,
            'sourceReleaseId'     => $parentReleaseId,
            'migratedToolsDigest' => self::digest($legacyDigestPayload),
            'retiredCommands'     => self::RETIRED_COMMANDS,
        ];
        $manifestJson = self::json($manifest);
        $seed         = new SpaceReleaseSeed(
            model: (string) $parent['model'],
            prompt: (string) $parent['prompt'],
            personalityJson: (string) $parent['personality_json'],
            manifestJson: $manifestJson,
            capabilityPolicyJson: (string) $parent['capability_policy_json'],
            artifactDigest: $parent['artifact_digest'] === null ? null : (string) $parent['artifact_digest'],
            createdBy: self::CREATOR,
        );
        $releaseDigest = $seed->digest();
        $sequence      = (int) $database->query(
            'SELECT COALESCE(MAX(sequence), 0) FROM space_releases WHERE space_id = ?',
            [$spaceId],
        )->fetchColumn() + 1;
        $releaseId = SpaceRecordId::forSeed(implode(':', [
            self::CREATOR,
            $spaceId,
            $parentReleaseId,
            $releaseDigest,
        ]));

        $database->execute(<<<'SQL'
            INSERT INTO space_releases (
                id, space_id, parent_release_id, source_proposal_id, sequence, status,
                release_digest, model, prompt, personality_json, manifest_json,
                capability_policy_json, artifact_digest, evaluation_digest,
                created_by, created_at, activated_at
            ) VALUES (?, ?, ?, NULL, ?, 'building', ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, NULL)
            SQL, [
            $releaseId,
            $spaceId,
            $parentReleaseId,
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
        ]);

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
            $database->execute(<<<'SQL'
                INSERT INTO space_skill_versions (
                    id, space_id, release_id, name, version, description, body,
                    manifest_json, source_digest, enabled, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                SQL, [
                SpaceRecordId::forSeed(implode(':', [
                    self::CREATOR,
                    $spaceId,
                    $releaseId,
                    $skill['name'],
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
                SqlBoolean::encode(true),
                $now,
            ]);
        }

        $generationBefore = (int) $target['release_generation'];
        $generationAfter  = $generationBefore + 1;
        if ($database->execute(
            "UPDATE space_releases SET status = 'retired' WHERE id = ? AND space_id = ? AND status = 'active'",
            [$parentReleaseId, $spaceId],
        ) !== 1 || $database->execute(
            "UPDATE space_releases SET status = 'active', activated_at = ? WHERE id = ? AND space_id = ? AND status = 'building'",
            [$now, $releaseId, $spaceId],
        ) !== 1 || $database->execute(<<<'SQL'
            UPDATE agent_spaces
            SET active_release_id = ?, release_generation = ?, updated_at = ?
            WHERE id = ? AND active_release_id = ? AND release_generation = ?
            SQL, [
            $releaseId,
            $generationAfter,
            $now,
            $spaceId,
            $parentReleaseId,
            $generationBefore,
        ]) !== 1) {
            throw new RuntimeException('Command release activation lost its compare-and-swap fence.');
        }

        $database->execute(<<<'SQL'
            INSERT INTO space_promotion_events (
                id, space_id, proposal_id, from_release_id, to_release_id, action,
                release_generation_before, release_generation_after, actor,
                policy_decision_json, created_at
            ) VALUES (?, ?, NULL, ?, ?, 'promote', ?, ?, ?, ?, ?)
            SQL, [
            SpaceRecordId::forSeed(implode(':', [self::CREATOR, $spaceId, (string) $generationAfter])),
            $spaceId,
            $parentReleaseId,
            $releaseId,
            $generationBefore,
            $generationAfter,
            self::CREATOR,
            self::json([
                'approved' => true,
                'mode'     => 'explicit-one-time-user-authorized-migration',
            ]),
            $now,
        ]);

        $snapshot = (new SpaceRuntimeSnapshotLoaderActivity(
            database: $database,
            tools: SpaceToolCatalog::wireDefinitions(),
        ))->loadSnapshot(new SpaceRuntimeSnapshotRequest(
            spaceId: $spaceId,
            batchId: self::CREATOR . ':' . $releaseId,
        ));
        $snapshotCommands = array_map(
            static fn (SpaceCommandBinding $command): array => [
                'command'          => $command->name,
                'description'      => $command->description,
                'instructions'     => $command->instructions,
                'parametersSchema' => $command->parametersSchema,
            ],
            $snapshot->commands,
        );
        $persistedCommands = json_decode(
            self::json(array_values($commands)),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        if ($snapshot->releaseId !== $releaseId || $snapshotCommands !== $persistedCommands) {
            throw new RuntimeException('The activated command release failed its pinned snapshot postcondition.');
        }

        return count($commands);
    }

    private function isMigratedManifest(string $json): bool
    {
        $manifest = self::objectJson($json, 'Space manifest');
        $marker   = $manifest['legacyCommandMigration'] ?? null;

        return is_array($marker) && ($marker['version'] ?? null) === 1;
    }
}
