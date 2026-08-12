<?php

declare(strict_types=1);

namespace Bot\Space\Sandbox;

use Closure;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

final class HttpSandboxBrokerClient implements SandboxBrokerInterface, CapsuleRegistryInterface
{
    /**
     * @var Closure(string, string, array<string, string>, string|null, int): array{status: int, body: string}|null
     */
    private readonly ?Closure $transport;

    /**
     * @param callable(string, string, array<string, string>, string|null, int): array{status: int, body: string}|null $transport
     * @param string                                                                                                   $baseUri
     * @param string                                                                                                   $token
     * @param int                                                                                                      $transportTimeoutMs
     */
    public function __construct(
        private readonly string $baseUri,
        private readonly string $token,
        private readonly int $transportTimeoutMs = 150_000,
        ?callable $transport = null,
    ) {
        if (filter_var($this->baseUri, \FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Sandbox broker base URI is invalid');
        }
        if (strlen($this->token) < 32) {
            throw new InvalidArgumentException('Sandbox broker token must contain at least 32 bytes');
        }
        if ($this->transportTimeoutMs <= 0) {
            throw new InvalidArgumentException('Sandbox broker transport timeout must be positive');
        }
        $this->transport = $transport === null ? null : Closure::fromCallable($transport);
    }

    public function execute(SandboxExecutionRequest $request): SandboxExecutionResult
    {
        $body = json_encode(
            $request->toArray(),
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
        $response = $this->send(
            'POST',
            '/v1/runs:execute',
            ['Content-Type' => 'application/json', 'Idempotency-Key' => $request->runId],
            $body,
            max($this->transportTimeoutMs, $request->limits->timeoutMs + 15_000),
        );
        $decoded = self::decode($response['body']);
        if ($response['status'] !== 200) {
            throw self::remoteError($response['status'], $decoded);
        }

        try {
            $result = SandboxExecutionResult::fromArray($decoded);
        } catch (UnexpectedValueException $error) {
            throw new SandboxExecutionException(
                errorCode: 'invalid_response',
                httpStatus: 502,
                message: 'Sandbox broker returned an invalid response',
                previous: $error,
            );
        }
        if ($result->runId !== $request->runId) {
            throw new SandboxExecutionException(
                errorCode: 'run_id_mismatch',
                httpStatus: 502,
                message: 'Sandbox broker returned a result for a different run',
            );
        }
        if ($result->audit['capsuleSha256'] !== substr($request->capsuleDigest, strlen('sha256:'))
            || $result->audit['releaseDigest'] !== $request->releaseDigest
            || !hash_equals($request->imageBuildId, $result->audit['imageBuildId'])
        ) {
            throw new SandboxExecutionException(
                errorCode: 'audit_mismatch',
                httpStatus: 502,
                message: 'Sandbox broker audit does not match the requested release, capsule, and runtime image',
            );
        }

        return $result;
    }

    public function stage(CapsuleStageRequest $request): CapsuleStageResult
    {
        $body = json_encode(
            $request->toArray(),
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
        $response = $this->send(
            'POST',
            '/v1/capsules:stage',
            ['Content-Type' => 'application/json', 'Idempotency-Key' => $request->idempotencyKey()],
            $body,
            $this->transportTimeoutMs,
        );
        $decoded = self::decode($response['body']);
        if ($response['status'] !== 200) {
            throw self::remoteError($response['status'], $decoded);
        }

        try {
            $result = CapsuleStageResult::fromArray($decoded);
        } catch (UnexpectedValueException $error) {
            throw new SandboxExecutionException(
                errorCode: 'invalid_response',
                httpStatus: 502,
                message: 'Sandbox broker returned an invalid capsule response',
                previous: $error,
            );
        }
        if ($result->entrypoint !== ['/data/capsule/' . $request->entrypoint]) {
            throw new SandboxExecutionException(
                errorCode: 'entrypoint_mismatch',
                httpStatus: 502,
                message: 'Sandbox broker returned a different capsule entrypoint',
            );
        }

        return $result;
    }

    public function cancel(string $runId): string
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $runId) !== 1) {
            throw new InvalidArgumentException('runId is not a valid identifier');
        }
        $response = $this->send('DELETE', '/v1/runs/' . rawurlencode($runId), [], null, $this->transportTimeoutMs);
        $decoded  = self::decode($response['body']);
        if (!in_array($response['status'], [200, 202], true)) {
            throw self::remoteError($response['status'], $decoded);
        }
        $status = $decoded['status'] ?? null;
        if (!in_array($status, ['cancellation_requested', 'terminal'], true)) {
            throw new SandboxExecutionException('invalid_response', 502, 'Sandbox broker returned an invalid cancellation response');
        }

        return $status;
    }

    /**
     * @param array<string, string> $headers
     * @param string                $method
     * @param string                $uri
     * @param ?string               $body
     * @param int                   $timeoutMs
     *
     * @return array{status: int, body: string}
     */
    private static function nativeTransport(string $method, string $uri, array $headers, ?string $body, int $timeoutMs): array
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $context = stream_context_create([
            'http' => [
                'method'          => $method,
                'header'          => implode("\r\n", $headerLines),
                'content'         => $body ?? '',
                'timeout'         => max(1, (int) ceil($timeoutMs / 1000)),
                'ignore_errors'   => true,
                'follow_location' => 0,
                'max_redirects'   => 0,
            ],
        ]);

        $responseHeaders = [];
        set_error_handler(static fn (int $severity, string $message): never => throw new RuntimeException($message, $severity));

        try {
            $responseBody    = file_get_contents($uri, false, $context);
            $responseHeaders = $http_response_header ?? [];
        } finally {
            restore_error_handler();
        }
        if ($responseBody === false) {
            throw new RuntimeException('Sandbox broker returned no response');
        }
        $statusLine = $responseHeaders[0] ?? '';
        if (preg_match('/\AHTTP\/\S+\s+(\d{3})\b/', $statusLine, $matches) !== 1) {
            throw new RuntimeException('Sandbox broker returned no HTTP status');
        }

        return ['status' => (int) $matches[1], 'body' => $responseBody];
    }

    /**
     * @param string $body
     *
     * @return array<string, mixed>
     */
    private static function decode(string $body): array
    {
        try {
            $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new SandboxExecutionException('invalid_json_response', 502, 'Sandbox broker returned invalid JSON', $error);
        }
        if (!is_array($decoded)) {
            throw new SandboxExecutionException('invalid_response', 502, 'Sandbox broker returned an invalid response');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $response
     * @param int                  $status
     */
    private static function remoteError(int $status, array $response): SandboxExecutionException
    {
        $error   = $response['error'] ?? null;
        $code    = is_array($error) && is_string($error['code'] ?? null) ? $error['code'] : 'broker_error';
        $message = is_array($error) && is_string($error['message'] ?? null)
            ? $error['message']
            : 'Sandbox broker rejected the request';

        return new SandboxExecutionException($code, $status, $message);
    }

    /**
     * @param array<string, string> $headers
     * @param string                $method
     * @param string                $path
     * @param ?string               $body
     * @param int                   $timeoutMs
     *
     * @return array{status: int, body: string}
     */
    private function send(string $method, string $path, array $headers, ?string $body, int $timeoutMs): array
    {
        $headers['Authorization'] = 'Bearer ' . $this->token;
        $headers['Accept']        = 'application/json';
        $uri                      = rtrim($this->baseUri, '/') . $path;

        try {
            if ($this->transport !== null) {
                return ($this->transport)($method, $uri, $headers, $body, $timeoutMs);
            }

            return self::nativeTransport($method, $uri, $headers, $body, $timeoutMs);
        } catch (SandboxExecutionException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new SandboxExecutionException(
                errorCode: 'transport_error',
                httpStatus: 502,
                message: 'Sandbox broker request failed',
                previous: $error,
            );
        }
    }
}
