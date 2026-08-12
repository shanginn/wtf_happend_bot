<?php

declare(strict_types=1);

namespace Bot\Space\Tools;

use Bot\Llm\Runtime\RuntimeCapabilityValidator;
use Bot\Space\Sandbox\GondolinImageBuildId;
use Bot\Space\Sandbox\SandboxBrokerInterface;
use Bot\Space\Sandbox\SandboxExecutionRequest;
use Bot\Space\Sandbox\SandboxResourceLimits;
use Cycle\Database\DatabaseInterface;
use InvalidArgumentException;
use PiPHP\AI\Content\ToolCallContent;
use PiPHP\AI\Tool\Tool;
use PiPHP\AI\Tool\ToolValidator;
use RuntimeException;
use Throwable;

final readonly class SpaceCapsuleExecutor
{
    private const int MAX_TOOL_OUTPUT_BYTES = 65_536;

    public function __construct(
        private DatabaseInterface $database,
        private SandboxBrokerInterface $sandbox,
        private string $imageBuildId,
        private ToolValidator $validator = new ToolValidator(),
    ) {
        if (!GondolinImageBuildId::isValid($this->imageBuildId)) {
            throw new InvalidArgumentException('The configured Gondolin image build ID must be a canonical UUID.');
        }
    }

    /** @param array<string, mixed> $arguments */
    public function execute(
        string $spaceId,
        string $releaseId,
        string $releaseDigest,
        string $name,
        array $arguments,
        string $idempotencyKey,
    ): string {
        $row = $this->database->query(<<<'SQL'
            SELECT manifest_json, release_digest
            FROM space_releases
            WHERE id = ? AND space_id = ?
            SQL, [$releaseId, $spaceId])->fetch();
        if (!is_array($row) || !hash_equals((string) $row['release_digest'], $releaseDigest)) {
            throw new RuntimeException('The pinned Space release is unavailable or has changed.');
        }
        $manifest = json_decode((string) $row['manifest_json'], true, flags: \JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || array_is_list($manifest)) {
            throw new RuntimeException('The pinned Space release manifest is invalid.');
        }
        $releaseImageBuildId = $manifest['capsuleRuntimeImageBuildId'] ?? null;
        if (!GondolinImageBuildId::isValid($releaseImageBuildId)
            || !hash_equals($this->imageBuildId, $releaseImageBuildId)
        ) {
            throw new RuntimeException(
                'The pinned capsule release targets a different or invalid Gondolin image build.',
            );
        }
        $capsules = is_array($manifest) && is_array($manifest['capsules'] ?? null)
            ? $manifest['capsules']
            : [];
        $capsule = null;
        foreach ($capsules as $candidate) {
            if (is_array($candidate) && ($candidate['name'] ?? null) === $name) {
                $capsule = $candidate;

                break;
            }
        }
        if (!is_array($capsule)) {
            throw new RuntimeException(sprintf('Capsule "%s" is not part of the pinned Space release.', $name));
        }
        $digest     = $capsule['digest'] ?? null;
        $entrypoint = $capsule['entrypoint'] ?? null;
        if (!is_string($digest) || !is_array($entrypoint) || !array_is_list($entrypoint)) {
            throw new RuntimeException('The pinned capsule manifest is invalid.');
        }
        $schema = $capsule['parametersSchema'] ?? null;
        if (!is_array($schema)) {
            throw new RuntimeException('The pinned capsule argument schema is invalid.');
        }

        try {
            $arguments = $this->validator->validate(
                new Tool(
                    name: $name,
                    description: (string) ($capsule['description'] ?? $name),
                    parameters: RuntimeCapabilityValidator::normalizeParametersSchema($schema),
                ),
                new ToolCallContent(
                    id: $idempotencyKey,
                    name: $name,
                    arguments: $arguments,
                ),
            );
        } catch (Throwable $error) {
            return sprintf('Capsule "%s" rejected its arguments: %s', $name, $error->getMessage());
        }

        $result = $this->sandbox->execute(new SandboxExecutionRequest(
            runId: 'run_' . substr(hash('sha256', $idempotencyKey), 0, 48),
            spaceId: $spaceId,
            releaseId: $releaseId,
            releaseDigest: $releaseDigest,
            capsuleDigest: $digest,
            imageBuildId: $releaseImageBuildId,
            entrypoint: $entrypoint,
            input: ['arguments' => $arguments],
            limits: new SandboxResourceLimits(),
        ));
        if ($result->status !== 'completed') {
            return sprintf(
                'Capsule "%s" failed in the isolated VM: %s',
                $name,
                $result->error['message'] ?? $result->stderr['text'],
            );
        }
        if ($result->stdout['truncated']) {
            return sprintf('Capsule "%s" failed: stdout exceeded the safe limit.', $name);
        }
        if ($result->stderr['truncated'] || trim($result->stderr['text']) !== '') {
            return sprintf('Capsule "%s" failed: the capsule wrote to stderr.', $name);
        }
        if ($result->artifacts !== []) {
            return sprintf('Capsule "%s" failed: output files are not allowed.', $name);
        }

        try {
            $decoded   = json_decode(trim($result->stdout['text']), true, flags: \JSON_THROW_ON_ERROR);
            $canonical = json_encode(
                $decoded,
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
            );
        } catch (Throwable) {
            return sprintf('Capsule "%s" failed: stdout must contain exactly one JSON value.', $name);
        }
        if (strlen($canonical) > self::MAX_TOOL_OUTPUT_BYTES) {
            return sprintf('Capsule "%s" failed: JSON output exceeded the tool-result limit.', $name);
        }

        return $canonical;
    }
}
