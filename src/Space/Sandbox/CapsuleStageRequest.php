<?php

declare(strict_types=1);

namespace Bot\Space\Sandbox;

use InvalidArgumentException;

final readonly class CapsuleStageRequest
{
    private const string IDENTIFIER_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D';

    public function __construct(
        public string $proposalId,
        public string $spaceId,
        public string $name,
        public string $source,
        public string $entrypoint = 'run.mjs',
    ) {
        self::identifier($this->proposalId, 'proposalId');
        self::identifier($this->spaceId, 'spaceId');
        if (preg_match('/\A[a-z][a-z0-9-]{0,63}\z/D', $this->name) !== 1) {
            throw new InvalidArgumentException('name must be a lowercase slug containing at most 64 characters');
        }
        if ($this->source === '' || strlen($this->source) > 65_536 || str_contains($this->source, "\0")) {
            throw new InvalidArgumentException('source must contain 1 to 65536 UTF-8 bytes without NUL');
        }
        if (!mb_check_encoding($this->source, 'UTF-8')) {
            throw new InvalidArgumentException('source must be valid UTF-8');
        }
        if (str_starts_with($this->source, '#!')) {
            throw new InvalidArgumentException('source must not provide a shebang');
        }
        self::entrypoint($this->entrypoint);
    }

    /**
     * @return array{
     *     apiVersion: string,
     *     proposalId: string,
     *     spaceId: string,
     *     name: string,
     *     language: 'javascript',
     *     source: string,
     *     entrypoint: string
     * }
     */
    public function toArray(): array
    {
        return [
            'apiVersion' => SandboxExecutionRequest::API_VERSION,
            'proposalId' => $this->proposalId,
            'spaceId'    => $this->spaceId,
            'name'       => $this->name,
            'language'   => 'javascript',
            'source'     => $this->source,
            'entrypoint' => $this->entrypoint,
        ];
    }

    public function idempotencyKey(): string
    {
        return $this->proposalId . ':' . $this->name;
    }

    private static function identifier(string $value, string $name): void
    {
        if (preg_match(self::IDENTIFIER_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(sprintf('%s is not a valid identifier', $name));
        }
    }

    private static function entrypoint(string $value): void
    {
        if ($value === '' || strlen($value) > 128 || str_starts_with($value, '/') || str_contains($value, '\\') || str_contains($value, "\0")) {
            throw new InvalidArgumentException('entrypoint must be a normalized relative POSIX path');
        }
        $segments = explode('/', $value);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $segment) !== 1) {
                throw new InvalidArgumentException('entrypoint contains an unsafe path segment');
            }
        }
        if (!str_ends_with($value, '.js') && !str_ends_with($value, '.mjs')) {
            throw new InvalidArgumentException('entrypoint must end in .js or .mjs');
        }
    }
}
