<?php

declare(strict_types=1);

namespace Bot\Space\Sandbox;

use UnexpectedValueException;

final readonly class CapsuleStageResult
{
    /**
     * @param list<string> $entrypoint
     * @param string       $digest
     */
    private function __construct(
        public string $digest,
        public array $entrypoint,
    ) {}

    /**
     * @param array<string, mixed> $value
     */
    public static function fromArray(array $value): self
    {
        $digest     = $value['digest'] ?? null;
        $entrypoint = $value['entrypoint'] ?? null;
        if (!is_string($digest) || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $digest) !== 1) {
            throw new UnexpectedValueException('Capsule stage response digest is invalid');
        }
        if (!is_array($entrypoint)
            || !array_is_list($entrypoint)
            || count($entrypoint) !== 1
            || !is_string($entrypoint[0])
            || !str_starts_with($entrypoint[0], '/data/capsule/')
        ) {
            throw new UnexpectedValueException('Capsule stage response entrypoint is invalid');
        }

        return new self($digest, $entrypoint);
    }
}
