<?php

declare(strict_types=1);

namespace Bot\Space\Sandbox;

use UnexpectedValueException;

final readonly class SandboxExecutionResult
{
    private const array STATUSES = ['completed', 'failed', 'timed_out', 'cancelled'];

    /**
     * @param array{text: string, bytesSeen: int, bytesCaptured: int, truncated: bool} $stdout
     * @param array{text: string, bytesSeen: int, bytesCaptured: int, truncated: bool} $stderr
     * @param list<array{path: string, ref: string, sha256: string, sizeBytes: int}>   $artifacts
     * @param array{code: string, message: string}|null                                $error
     * @param array{
     *     requestSha256: string,
     *     capsuleSha256: string,
     *     releaseDigest: string,
     *     imageBuildId: string,
     *     gondolinCommit: string,
     *     vmId: null|string,
     *     startedAt: string,
     *     finishedAt: string,
     *     durationMs: int
     * } $audit
     * @param string $runId
     * @param string $status
     * @param ?int   $exitCode
     * @param ?int   $signal
     */
    private function __construct(
        public string $runId,
        public string $status,
        public ?int $exitCode,
        public ?int $signal,
        public array $stdout,
        public array $stderr,
        public array $artifacts,
        public ?array $error,
        public array $audit,
    ) {}

    /**
     * @param array<string, mixed> $value
     */
    public static function fromArray(array $value): self
    {
        if (($value['apiVersion'] ?? null) !== SandboxExecutionRequest::API_VERSION) {
            throw new UnexpectedValueException('Sandbox response has an unsupported apiVersion');
        }

        $runId  = self::string($value, 'runId');
        $status = self::string($value, 'status');
        if (!in_array($status, self::STATUSES, true)) {
            throw new UnexpectedValueException('Sandbox response has an invalid status');
        }

        $exitCode  = self::nullableInt($value, 'exitCode');
        $signal    = self::nullableInt($value, 'signal');
        $stdout    = self::capturedOutput($value['stdout'] ?? null, 'stdout');
        $stderr    = self::capturedOutput($value['stderr'] ?? null, 'stderr');
        $artifacts = self::artifacts($value['artifacts'] ?? null);
        $error     = self::error($value['error'] ?? null);
        $audit     = self::audit($value['audit'] ?? null);

        if ($status === 'completed' && ($exitCode !== 0 || $error !== null)) {
            throw new UnexpectedValueException('Completed sandbox response is inconsistent');
        }
        if ($status !== 'completed' && $error === null) {
            throw new UnexpectedValueException('Failed sandbox response must contain an error');
        }

        return new self(
            runId: $runId,
            status: $status,
            exitCode: $exitCode,
            signal: $signal,
            stdout: $stdout,
            stderr: $stderr,
            artifacts: $artifacts,
            error: $error,
            audit: $audit,
        );
    }

    /**
     * @param array<string, mixed> $value
     * @param string               $key
     */
    private static function string(array $value, string $key): string
    {
        $result = $value[$key] ?? null;
        if (!is_string($result) || $result === '') {
            throw new UnexpectedValueException(sprintf('Sandbox response field %s must be a string', $key));
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $value
     * @param string               $key
     */
    private static function nullableInt(array $value, string $key): ?int
    {
        $result = $value[$key] ?? null;
        if ($result !== null && !is_int($result)) {
            throw new UnexpectedValueException(sprintf('Sandbox response field %s must be an integer or null', $key));
        }

        return $result;
    }

    /**
     * @param mixed  $value
     * @param string $name
     *
     * @return array{text: string, bytesSeen: int, bytesCaptured: int, truncated: bool}
     */
    private static function capturedOutput(mixed $value, string $name): array
    {
        if (!is_array($value)
            || !is_string($value['text'] ?? null)
            || !is_int($value['bytesSeen'] ?? null)
            || !is_int($value['bytesCaptured'] ?? null)
            || !is_bool($value['truncated'] ?? null)
            || $value['bytesSeen'] < $value['bytesCaptured']
            || $value['bytesCaptured'] < 0
        ) {
            throw new UnexpectedValueException(sprintf('Sandbox response field %s is invalid', $name));
        }

        return [
            'text'          => $value['text'],
            'bytesSeen'     => $value['bytesSeen'],
            'bytesCaptured' => $value['bytesCaptured'],
            'truncated'     => $value['truncated'],
        ];
    }

    /**
     * @param mixed $value
     *
     * @return list<array{path: string, ref: string, sha256: string, sizeBytes: int}>
     */
    private static function artifacts(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new UnexpectedValueException('Sandbox response artifacts must be a list');
        }

        $result = [];
        foreach ($value as $artifact) {
            if (!is_array($artifact)
                || !is_string($artifact['path'] ?? null)
                || !is_string($artifact['ref'] ?? null)
                || !is_string($artifact['sha256'] ?? null)
                || !is_int($artifact['sizeBytes'] ?? null)
                || $artifact['sizeBytes'] < 0
                || preg_match('/\A[a-f0-9]{64}\z/D', $artifact['sha256']) !== 1
                || $artifact['ref'] !== 'sha256:' . $artifact['sha256']
            ) {
                throw new UnexpectedValueException('Sandbox response contains an invalid artifact');
            }
            $result[] = [
                'path'      => $artifact['path'],
                'ref'       => $artifact['ref'],
                'sha256'    => $artifact['sha256'],
                'sizeBytes' => $artifact['sizeBytes'],
            ];
        }

        return $result;
    }

    /**
     * @param mixed $value
     *
     * @return array{code: string, message: string}|null
     */
    private static function error(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || !is_string($value['code'] ?? null) || !is_string($value['message'] ?? null)) {
            throw new UnexpectedValueException('Sandbox response error is invalid');
        }

        return ['code' => $value['code'], 'message' => $value['message']];
    }

    /**
     * @param mixed $value
     *
     * @return array{
     *     requestSha256: string,
     *     capsuleSha256: string,
     *     releaseDigest: string,
     *     imageBuildId: string,
     *     gondolinCommit: string,
     *     vmId: null|string,
     *     startedAt: string,
     *     finishedAt: string,
     *     durationMs: int
     * }
     */
    private static function audit(mixed $value): array
    {
        if (!is_array($value)) {
            throw new UnexpectedValueException('Sandbox response audit is invalid');
        }
        foreach (['requestSha256', 'capsuleSha256', 'releaseDigest', 'imageBuildId', 'gondolinCommit', 'startedAt', 'finishedAt'] as $key) {
            if (!is_string($value[$key] ?? null) || $value[$key] === '') {
                throw new UnexpectedValueException(sprintf('Sandbox response audit.%s is invalid', $key));
            }
        }
        foreach (['requestSha256', 'capsuleSha256'] as $key) {
            if (preg_match('/\A[a-f0-9]{64}\z/D', $value[$key]) !== 1) {
                throw new UnexpectedValueException(sprintf('Sandbox response audit.%s is not a SHA-256 digest', $key));
            }
        }
        if (preg_match('/\Asha256:[a-f0-9]{64}\z/D', $value['releaseDigest']) !== 1) {
            throw new UnexpectedValueException('Sandbox response audit.releaseDigest is invalid');
        }
        if (!GondolinImageBuildId::isValid($value['imageBuildId'])) {
            throw new UnexpectedValueException('Sandbox response audit.imageBuildId is invalid');
        }
        if (($value['vmId'] ?? null) !== null && !is_string($value['vmId'])) {
            throw new UnexpectedValueException('Sandbox response audit.vmId is invalid');
        }
        if (!is_int($value['durationMs'] ?? null) || $value['durationMs'] < 0) {
            throw new UnexpectedValueException('Sandbox response audit.durationMs is invalid');
        }

        return [
            'requestSha256'  => $value['requestSha256'],
            'capsuleSha256'  => $value['capsuleSha256'],
            'releaseDigest'  => $value['releaseDigest'],
            'imageBuildId'   => $value['imageBuildId'],
            'gondolinCommit' => $value['gondolinCommit'],
            'vmId'           => $value['vmId'] ?? null,
            'startedAt'      => $value['startedAt'],
            'finishedAt'     => $value['finishedAt'],
            'durationMs'     => $value['durationMs'],
        ];
    }
}
