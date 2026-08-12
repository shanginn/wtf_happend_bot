<?php

declare(strict_types=1);

namespace Bot\Space\Runtime;

use Bot\Space\Sandbox\GondolinImageBuildId;
use InvalidArgumentException;

/**
 * Immutable, self-contained runtime selection for exactly one agent batch.
 * Executable capsules are represented only by content-addressed references;
 * workflow code must never load or execute them.
 */
final readonly class SpaceRuntimeSnapshot
{
    /**
     * @param list<array<string, mixed>> $tools
     * @param list<array<string, mixed>> $capsuleArtifactRefs
     * @param string                     $snapshotId
     * @param string                     $spaceId
     * @param string                     $releaseId
     * @param string                     $releaseDigest
     * @param string                     $model
     * @param string                     $systemPrompt
     * @param string                     $memoryRevision
     * @param string                     $capabilityPolicyRevision
     * @param ?string                    $capsuleRuntimeImageBuildId
     */
    public function __construct(
        public string $snapshotId,
        public string $spaceId,
        public string $releaseId,
        public string $releaseDigest,
        public string $model,
        public string $systemPrompt,
        public array $tools,
        public array $capsuleArtifactRefs = [],
        public ?string $capsuleRuntimeImageBuildId = null,
        public string $memoryRevision = 'none',
        public string $capabilityPolicyRevision = 'none',
    ) {
        foreach (
            [
                'snapshot ID'                => $snapshotId,
                'Space ID'                   => $spaceId,
                'release ID'                 => $releaseId,
                'release digest'             => $releaseDigest,
                'model'                      => $model,
                'system prompt'              => $systemPrompt,
                'memory revision'            => $memoryRevision,
                'capability policy revision' => $capabilityPolicyRevision,
            ] as $label => $value
        ) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Space runtime {$label} cannot be empty.");
            }
        }

        if (!array_is_list($tools) || !array_is_list($capsuleArtifactRefs)) {
            throw new InvalidArgumentException(
                'Space runtime tools and capsule artifact references must be lists.',
            );
        }
        foreach ($tools as $index => $tool) {
            if (!is_array($tool)) {
                throw new InvalidArgumentException(
                    sprintf('Space runtime tool %d must be an object.', $index),
                );
            }
        }
        foreach ($capsuleArtifactRefs as $index => $artifactRef) {
            if (!is_array($artifactRef)) {
                throw new InvalidArgumentException(
                    sprintf('Space capsule artifact reference %d must be an object.', $index),
                );
            }
            $digest = $artifactRef['digest'] ?? null;
            if (!is_string($digest) || trim($digest) === '') {
                throw new InvalidArgumentException(
                    sprintf('Space capsule artifact reference %d needs a digest.', $index),
                );
            }
        }
        if ($capsuleArtifactRefs !== [] && !GondolinImageBuildId::isValid($capsuleRuntimeImageBuildId)) {
            throw new InvalidArgumentException(
                'Space runtime capsule references require a canonical Gondolin image build ID.',
            );
        }
        if ($capsuleArtifactRefs === [] && $capsuleRuntimeImageBuildId !== null) {
            throw new InvalidArgumentException(
                'Space runtime cannot pin a Gondolin image without capsule references.',
            );
        }
    }

    /**
     * Small data-only metadata suitable for Temporal history and the PiPH child.
     *
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return [
            'runtimeSnapshotId'          => $this->snapshotId,
            'releaseId'                  => $this->releaseId,
            'releaseDigest'              => $this->releaseDigest,
            'memoryRevision'             => $this->memoryRevision,
            'capabilityPolicyRevision'   => $this->capabilityPolicyRevision,
            'capsuleArtifactRefs'        => $this->capsuleArtifactRefs,
            'capsuleRuntimeImageBuildId' => $this->capsuleRuntimeImageBuildId,
        ];
    }
}
