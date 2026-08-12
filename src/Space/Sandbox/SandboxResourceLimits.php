<?php

declare(strict_types=1);

namespace Bot\Space\Sandbox;

use InvalidArgumentException;

final readonly class SandboxResourceLimits
{
    public function __construct(
        public int $timeoutMs = 30_000,
        public int $maxStdoutBytes = 262_144,
        public int $maxStderrBytes = 262_144,
        public int $maxOutputBytes = 4_194_304,
        public int $maxOutputFiles = 32,
        public int $memoryMiB = 256,
        public int $cpus = 1,
    ) {
        self::positive($this->timeoutMs, 'timeoutMs');
        self::nonNegative($this->maxStdoutBytes, 'maxStdoutBytes');
        self::nonNegative($this->maxStderrBytes, 'maxStderrBytes');
        self::nonNegative($this->maxOutputBytes, 'maxOutputBytes');
        self::nonNegative($this->maxOutputFiles, 'maxOutputFiles');
        if ($this->memoryMiB < 64) {
            throw new InvalidArgumentException('memoryMiB must be at least 64');
        }
        self::positive($this->cpus, 'cpus');
    }

    /**
     * @return array{
     *     timeoutMs: int,
     *     maxStdoutBytes: int,
     *     maxStderrBytes: int,
     *     maxOutputBytes: int,
     *     maxOutputFiles: int,
     *     memoryMiB: int,
     *     cpus: int
     * }
     */
    public function toArray(): array
    {
        return [
            'timeoutMs'      => $this->timeoutMs,
            'maxStdoutBytes' => $this->maxStdoutBytes,
            'maxStderrBytes' => $this->maxStderrBytes,
            'maxOutputBytes' => $this->maxOutputBytes,
            'maxOutputFiles' => $this->maxOutputFiles,
            'memoryMiB'      => $this->memoryMiB,
            'cpus'           => $this->cpus,
        ];
    }

    private static function positive(int $value, string $name): void
    {
        if ($value <= 0) {
            throw new InvalidArgumentException(sprintf('%s must be positive', $name));
        }
    }

    private static function nonNegative(int $value, string $name): void
    {
        if ($value < 0) {
            throw new InvalidArgumentException(sprintf('%s must not be negative', $name));
        }
    }
}
