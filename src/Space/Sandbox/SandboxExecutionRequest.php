<?php

declare(strict_types=1);

namespace Bot\Space\Sandbox;

use InvalidArgumentException;

final readonly class SandboxExecutionRequest
{
    public const string API_VERSION = 'sandbox.wtf/v1';

    private const string IDENTIFIER_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D';
    private const string SHA256_PATTERN     = '/\Asha256:[a-f0-9]{64}\z/D';

    /**
     * @param list<string>          $entrypoint
     * @param string                $runId
     * @param string                $spaceId
     * @param string                $releaseId
     * @param string                $releaseDigest
     * @param string                $capsuleDigest
     * @param string                $imageBuildId
     * @param mixed                 $input
     * @param SandboxResourceLimits $limits
     */
    public function __construct(
        public string $runId,
        public string $spaceId,
        public string $releaseId,
        public string $releaseDigest,
        public string $capsuleDigest,
        public string $imageBuildId,
        public array $entrypoint,
        public mixed $input,
        public SandboxResourceLimits $limits = new SandboxResourceLimits(),
    ) {
        self::identifier($this->runId, 'runId');
        self::identifier($this->spaceId, 'spaceId');
        self::identifier($this->releaseId, 'releaseId');
        self::digest($this->releaseDigest, 'releaseDigest');
        self::digest($this->capsuleDigest, 'capsuleDigest');
        if (!GondolinImageBuildId::isValid($this->imageBuildId)) {
            throw new InvalidArgumentException('imageBuildId must be a canonical UUID');
        }

        if ($this->entrypoint === [] || count($this->entrypoint) > 64) {
            throw new InvalidArgumentException('entrypoint must contain 1 to 64 arguments');
        }
        foreach ($this->entrypoint as $index => $argument) {
            if (!is_string($argument) || $argument === '' || strlen($argument) > 4096 || str_contains($argument, "\0")) {
                throw new InvalidArgumentException(sprintf('entrypoint[%d] is invalid', $index));
            }
        }
        if (!str_starts_with($this->entrypoint[0], '/data/capsule/')) {
            throw new InvalidArgumentException('entrypoint executable must be inside /data/capsule');
        }
        $segments = explode('/', $this->entrypoint[0]);
        if (in_array('.', $segments, true) || in_array('..', $segments, true) || str_contains($this->entrypoint[0], '\\')) {
            throw new InvalidArgumentException('entrypoint executable must be a normalized POSIX path without traversal');
        }

        json_encode($this->input, \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{
     *     apiVersion: string,
     *     runId: string,
     *     spaceId: string,
     *     release: array{id: string, digest: string},
     *     capsule: array{digest: string, entrypoint: list<string>},
     *     runtime: array{imageBuildId: string},
     *     input: mixed,
     *     limits: array<string, int>,
     *     network: array{mode: 'deny'}
     * }
     */
    public function toArray(): array
    {
        return [
            'apiVersion' => self::API_VERSION,
            'runId'      => $this->runId,
            'spaceId'    => $this->spaceId,
            'release'    => [
                'id'     => $this->releaseId,
                'digest' => $this->releaseDigest,
            ],
            'capsule' => [
                'digest'     => $this->capsuleDigest,
                'entrypoint' => array_values($this->entrypoint),
            ],
            'runtime' => [
                'imageBuildId' => $this->imageBuildId,
            ],
            'input'   => $this->input,
            'limits'  => $this->limits->toArray(),
            'network' => ['mode' => 'deny'],
        ];
    }

    private static function identifier(string $value, string $name): void
    {
        if (preg_match(self::IDENTIFIER_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(sprintf('%s is not a valid identifier', $name));
        }
    }

    private static function digest(string $value, string $name): void
    {
        if (preg_match(self::SHA256_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(sprintf('%s must be sha256:<64 lowercase hex>', $name));
        }
    }
}
